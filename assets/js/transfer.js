/**
 * Import / Export screen: starts a database export or import over REST and
 * keeps calling its run endpoint until it finishes.
 *
 * Import steps are sent with the import's own key and without the login
 * cookie: importing can replace the users table and end the session, and the
 * import has to be able to finish anyway.
 *
 * Everything from the server is inserted with textContent, never as HTML.
 */
( function () {
	'use strict';

	const root = document.getElementById( 'crq-transfer' );

	if ( ! root ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const speak = wp.a11y.speak;
	const API = '/crq-relocate/v1';
	const KEY_HEADER = 'X-CRQ-Transfer-Key';
	const numbers = new Intl.NumberFormat( document.documentElement.lang || undefined );

	// Waits before each automatic retry of a failed step.
	const RETRY_DELAYS = [ 2000, 5000, 15000 ];

	const notices = document.getElementById( 'crq-transfer-notices' );
	const result = document.getElementById( 'crq-transfer-result' );
	const exportForm = document.getElementById( 'crq-export-form' );
	const importForm = document.getElementById( 'crq-import-form' );
	const progress = {
		panel: document.getElementById( 'crq-transfer-progress' ),
		heading: document.getElementById( 'crq-transfer-heading' ),
		text: document.getElementById( 'crq-transfer-text' ),
		percent: document.getElementById( 'crq-transfer-percent' ),
		bar: document.getElementById( 'crq-transfer-bar' ),
		fill: document.querySelector( '#crq-transfer-bar .crq-bar-fill' ),
		cancel: document.getElementById( 'crq-transfer-cancel' ),
	};

	let cancelRequested = false;

	progress.cancel.addEventListener( 'click', () => {
		cancelRequested = true;
		progress.cancel.disabled = true;
		progress.text.textContent = __( 'Stopping…', 'cr-relocate-db' );
	} );

	initPairLists();

	if ( exportForm ) {
		initExport();
	}

	if ( importForm ) {
		initImport();
	}

	/* Export ---------------------------------------------------------------- */

	/**
	 * "Add another" in the "Change text while exporting / importing" sections.
	 */
	function initPairLists() {
		root.addEventListener( 'click', ( event ) => {
			const add = event.target.closest( '[data-crq-add-pair]' );

			if ( ! add ) {
				return;
			}

			const list = document.getElementById( add.dataset.crqAddPair );
			const max = Number( list.dataset.max ) || 1;
			const row = list.firstElementChild.cloneNode( true );
			const number = list.children.length + 1;

			row.querySelectorAll( 'input' ).forEach( ( input ) => {
				input.value = '';
				input.id = input.id.replace( /\d+$/, number );
			} );
			row.querySelectorAll( 'label' ).forEach( ( label ) => {
				label.htmlFor = label.htmlFor.replace( /\d+$/, number );
			} );

			list.append( row );
			add.disabled = list.children.length >= max;
			row.querySelector( 'input' ).focus();
		} );
	}

	/**
	 * @param {HTMLFormElement} form
	 * @return {Object[]} Search and replacement pairs, leaving out rows left empty.
	 */
	function pairsFrom( form ) {
		const data = new FormData( form );
		const replaces = data.getAll( 'replace[]' );

		return data.getAll( 'search[]' )
			.map( ( search, index ) => ( { search, replace: replaces[ index ] || '' } ) )
			.filter( ( pair ) => pair.search || pair.replace );
	}

	function initExport() {
		exportForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			clearNotices();

			const data = new FormData( exportForm );
			const pairs = pairsFrom( exportForm );

			let transfer;
			try {
				transfer = await apiFetch( {
					path: `${ API }/exports`,
					method: 'POST',
					data: { scope: data.get( 'scope' ), pairs },
				} );
			} catch ( error ) {
				showNotice( 'error', errorMessage( error ) );
				return;
			}

			exportForm.hidden = true;
			speak( __( 'Export started.', 'cr-relocate-db' ) );
			run( transfer );
		} );
	}

	/* Import ---------------------------------------------------------------- */

	function initImport() {
		const confirm = document.getElementById( 'crq-import-confirm' );
		const file = document.getElementById( 'crq-import-file' );
		const submit = importForm.querySelector( '[type="submit"]' );

		confirm.addEventListener( 'change', () => {
			submit.disabled = ! confirm.checked;
		} );

		importForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			clearNotices();

			if ( ! file.files.length ) {
				file.reportValidity();
				return;
			}

			const body = new FormData();
			body.append( 'file', file.files[ 0 ] );
			body.append( 'confirmed', 'true' );
			pairsFrom( importForm ).forEach( ( pair, index ) => {
				body.append( `pairs[${ index }][search]`, pair.search );
				body.append( `pairs[${ index }][replace]`, pair.replace );
			} );

			submit.disabled = true;
			showProgress( __( 'Uploading the file…', 'cr-relocate-db' ), '' );

			let transfer;
			try {
				transfer = await apiFetch( { path: `${ API }/imports`, method: 'POST', body } );
			} catch ( error ) {
				progress.panel.hidden = true;
				submit.disabled = false;
				showNotice( 'error', errorMessage( error ) );
				return;
			}

			importForm.hidden = true;
			speak( __( 'Import started.', 'cr-relocate-db' ) );
			run( transfer, transfer.key );
		} );
	}

	/* Running --------------------------------------------------------------- */

	/**
	 * @param {Object}  transfer Export or import as returned by the REST API.
	 * @param {string=} key      An import's key, sent instead of the login cookie.
	 */
	async function run( transfer, key ) {
		cancelRequested = false;
		progress.cancel.disabled = false;
		showProgress(
			'export' === transfer.type ? __( 'Exporting the database', 'cr-relocate-db' ) : __( 'Importing the database', 'cr-relocate-db' ),
			__( 'Starting… Keep this page open until it finishes.', 'cr-relocate-db' )
		);
		window.addEventListener( 'beforeunload', warnBeforeLeaving );

		try {
			while ( ! transfer.finished ) {
				transfer = await step( transfer, key, cancelRequested ? 'cancel' : 'run' );
				updateProgress( transfer );
			}
		} catch ( error ) {
			window.removeEventListener( 'beforeunload', warnBeforeLeaving );
			progress.bar.classList.add( 'is-stopped' );
			showNotice( 'error', errorMessage( error ), () => run( transfer, key ), __( 'Continue', 'cr-relocate-db' ) );
			return;
		}

		window.removeEventListener( 'beforeunload', warnBeforeLeaving );
		progress.panel.hidden = true;
		renderResult( transfer, key );
	}

	/**
	 * One request, retried a few times when the failure is likely to pass.
	 * Repeating a step is safe: the server works from the last saved position.
	 *
	 * @param {Object}  transfer
	 * @param {string=} key
	 * @param {string}  action run, cancel or resume.
	 * @return {Promise<Object>} The transfer after the request.
	 */
	async function step( transfer, key, action ) {
		for ( let attempt = 0; ; attempt++ ) {
			try {
				return await request( transfer.id, key, action );
			} catch ( error ) {
				if ( attempt >= RETRY_DELAYS.length || ! isTransient( error ) ) {
					throw error;
				}

				progress.text.textContent = __( 'The connection was interrupted. Trying again…', 'cr-relocate-db' );
				await new Promise( ( resolve ) => setTimeout( resolve, RETRY_DELAYS[ attempt ] ) );
			}
		}
	}

	async function request( id, key, action ) {
		const path = `${ API }/transfers/${ id }/${ action }`;

		if ( ! key ) {
			return apiFetch( { path, method: 'POST' } );
		}

		let response;
		try {
			response = await window.fetch( root.dataset.restRoot + path.slice( 1 ), {
				method: 'POST',
				credentials: 'omit',
				headers: { [ KEY_HEADER ]: key, Accept: 'application/json' },
			} );
		} catch {
			throw { code: 'fetch_error', message: __( 'The request failed. Check your connection and try again.', 'cr-relocate-db' ) };
		}

		let body;
		try {
			body = await response.json();
		} catch {
			throw { code: 'invalid_json', data: { status: response.status }, message: __( 'The server sent an unexpected response.', 'cr-relocate-db' ) };
		}

		if ( ! response.ok ) {
			throw body;
		}

		return body;
	}

	function isTransient( error ) {
		const code = error && error.code;
		const status = error && error.data && error.data.status;

		return ! code || 'fetch_error' === code || 'invalid_json' === code || 'crq_relocate_job_busy' === code || status >= 500;
	}

	/* Progress and results -------------------------------------------------- */

	function showProgress( heading, text ) {
		result.hidden = true;
		progress.panel.hidden = false;
		progress.heading.textContent = heading;
		progress.text.textContent = text;
		progress.percent.textContent = '';
		progress.bar.className = 'crq-bar is-indeterminate';
		progress.fill.style.width = '';
	}

	function updateProgress( transfer ) {
		const percent = transfer.progress;

		progress.bar.classList.remove( 'is-indeterminate' );
		progress.fill.style.width = `${ percent }%`;
		progress.bar.setAttribute( 'aria-valuenow', String( percent ) );
		progress.percent.textContent = `${ percent }%`;

		if ( cancelRequested || transfer.finished ) {
			return;
		}

		if ( 'export' === transfer.type ) {
			progress.text.textContent = transfer.current_table
				? sprintf(
					/* translators: 1: table name, 2: table number, 3: number of tables, 4: rows written so far. */
					__( 'Writing %1$s (table %2$s of %3$s) · %4$s rows so far', 'cr-relocate-db' ),
					transfer.current_table,
					numbers.format( transfer.tables_done + 1 ),
					numbers.format( transfer.tables_total ),
					numbers.format( transfer.rows )
				)
				: __( 'Finishing…', 'cr-relocate-db' );
		} else {
			progress.text.textContent = 'unpack' === transfer.phase
				? __( 'Unpacking the file…', 'cr-relocate-db' )
				: sprintf(
					/* translators: %s: number of SQL statements run so far. */
					__( 'Running the file · %s statements so far', 'cr-relocate-db' ),
					numbers.format( transfer.statements )
				);
		}
	}

	function renderResult( transfer, key ) {
		const children = [];
		let message;

		if ( 'completed' === transfer.status ) {
			message = 'export' === transfer.type
				? __( 'Export complete. Your database was not changed.', 'cr-relocate-db' )
				: __( 'Import complete.', 'cr-relocate-db' );
			children.push( status( 'success', 'yes-alt', message ) );
		} else if ( 'cancelled' === transfer.status ) {
			message = 'export' === transfer.type
				? __( 'Export cancelled.', 'cr-relocate-db' )
				: __( 'Import cancelled. Statements that ran before stopping have changed the database; the rest of the file was not run.', 'cr-relocate-db' );
			children.push( status( 'warning', 'dismiss', message ) );
		} else {
			message = transfer.error || __( 'It stopped because of an error.', 'cr-relocate-db' );
			children.push( status( 'error', 'warning', message ) );

			const resume = el( 'button', { type: 'button', className: 'button button-primary' }, __( 'Resume', 'cr-relocate-db' ) );
			resume.addEventListener( 'click', async () => {
				try {
					const resumed = await apiFetch( { path: `${ API }/transfers/${ transfer.id }/resume`, method: 'POST' } );
					run( resumed, key );
				} catch ( error ) {
					showNotice( 'error', errorMessage( error ) );
				}
			} );
			children.push( el( 'p', {}, __( 'Everything before the error was kept. Resuming carries on from there.', 'cr-relocate-db' ) ), el( 'p', {}, resume ) );
		}

		if ( 'export' === transfer.type ) {
			children.push( el( 'p', { className: 'crq-results-meta' }, exportSummary( transfer ) ) );

			if ( transfer.download_url ) {
				children.push(
					el(
						'p',
						{},
						el(
							'a',
							{ className: 'button button-primary crq-button-lg', href: transfer.download_url },
							el( 'span', { className: 'dashicons dashicons-download', ariaHidden: 'true' } ),
							/* translators: %s: file size. */
							sprintf( __( 'Download export (%s)', 'cr-relocate-db' ), transfer.size )
						)
					)
				);
			}
		} else {
			children.push( el( 'p', { className: 'crq-results-meta' }, importSummary( transfer ) ) );

			if ( transfer.relogin && 'completed' === transfer.status ) {
				children.push(
					el(
						'div',
						{ className: 'notice notice-warning inline' },
						el( 'p', {}, __( 'The users or options tables were replaced, so you may need to log in again, possibly at a new address.', 'cr-relocate-db' ) ),
						el( 'p', {}, el( 'a', { href: transfer.login_url }, __( 'Log in again', 'cr-relocate-db' ) ) )
					)
				);
			}
		}

		const again = el( 'a', { className: 'button', href: window.location.href }, 'export' === transfer.type ? __( 'Start another export', 'cr-relocate-db' ) : __( 'Import another file', 'cr-relocate-db' ) );
		children.push( el( 'p', {}, again ) );

		result.replaceChildren( el( 'section', { className: 'crq-card' }, ...children ) );
		result.hidden = false;
		speak( message );
	}

	function exportSummary( transfer ) {
		let text = sprintf(
			/* translators: 1: number of rows, 2: number of tables. */
			_n( '%1$s rows from %2$s table.', '%1$s rows from %2$s tables.', transfer.tables_total, 'cr-relocate-db' ),
			numbers.format( transfer.rows ),
			numbers.format( transfer.tables_total )
		);

		if ( transfer.pairs ) {
			text += ' ' + sprintf(
				/* translators: %s: number of replacements. */
				_n( '%s replacement made in the file.', '%s replacements made in the file.', transfer.replacements, 'cr-relocate-db' ),
				numbers.format( transfer.replacements )
			);
		}

		if ( transfer.skipped ) {
			text += ' ' + sprintf(
				/* translators: %s: number of values. */
				_n( '%s matching value was left unchanged because changing it could corrupt it.', '%s matching values were left unchanged because changing them could corrupt them.', transfer.skipped, 'cr-relocate-db' ),
				numbers.format( transfer.skipped )
			);
		}

		return text;
	}

	function importSummary( transfer ) {
		let text = sprintf(
			/* translators: 1: number of SQL statements, 2: number of tables. */
			__( '%1$s statements run, %2$s tables affected.', 'cr-relocate-db' ),
			numbers.format( transfer.statements ),
			numbers.format( transfer.tables )
		);

		if ( transfer.pairs ) {
			text += ' ' + sprintf(
				/* translators: %s: number of replacements. */
				_n( '%s replacement made while importing.', '%s replacements made while importing.', transfer.replacements, 'cr-relocate-db' ),
				numbers.format( transfer.replacements )
			);
		}

		if ( transfer.unchanged ) {
			text += ' ' + sprintf(
				/* translators: %s: number of values. */
				_n( '%s matching value was left unchanged because changing it could corrupt it.', '%s matching values were left unchanged because changing them could corrupt them.', transfer.unchanged, 'cr-relocate-db' ),
				numbers.format( transfer.unchanged )
			);
		}

		return text;
	}

	/* Helpers --------------------------------------------------------------- */

	function status( type, icon, text ) {
		return el(
			'p',
			{ className: `crq-status crq-status-${ type }` },
			el( 'span', { className: `dashicons dashicons-${ icon }`, ariaHidden: 'true' } ),
			text
		);
	}

	function showNotice( type, message, retry, label ) {
		const notice = el( 'div', { className: `notice notice-${ type }` }, el( 'p', {}, message ) );

		if ( retry ) {
			const button = el( 'button', { type: 'button', className: 'button' }, label );
			button.addEventListener( 'click', () => {
				notice.remove();
				retry();
			} );
			notice.append( el( 'p', {}, button ) );
		}

		notices.replaceChildren( notice );
		speak( message, 'assertive' );
	}

	function clearNotices() {
		notices.replaceChildren();
	}

	function errorMessage( error ) {
		return ( error && error.message ) || __( 'The request failed. Check your connection and try again.', 'cr-relocate-db' );
	}

	function warnBeforeLeaving( event ) {
		event.preventDefault();
		event.returnValue = '';
	}

	function el( tag, props = {}, ...children ) {
		const element = Object.assign( document.createElement( tag ), props );
		element.append( ...children.map( ( child ) => ( 'number' === typeof child ? String( child ) : child ) ) );
		return element;
	}
}() );
