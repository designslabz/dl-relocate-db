/**
 * Search & Replace screen and job page.
 *
 * Creates a dry run over REST, keeps calling its run endpoint until it
 * finishes, and renders the report. From a finished dry run the same loop
 * drives the live replacement. The job page embeds a job in
 * #crq-runner[data-job], which is rendered on load.
 *
 * Everything from the server is inserted with textContent, never as HTML.
 */
( function () {
	'use strict';

	const runner = document.getElementById( 'crq-runner' );

	if ( ! runner ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const speak = wp.a11y.speak;
	const API = '/crq-relocate/v1/jobs';
	const PAGE_SIZE = 25;

	// Waits before each automatic retry of a failed step: a dropped connection
	// or a proxy timeout usually clears within seconds.
	const RETRY_DELAYS = [ 2000, 5000, 15000 ];

	const form = document.getElementById( 'crq-search-replace' );
	const submitButton = form ? form.querySelector( '[type="submit"]' ) : null;
	const notices = document.getElementById( 'crq-notices' );
	const results = document.getElementById( 'crq-results' );
	const dialog = document.getElementById( 'crq-confirm' );
	const confirmBackup = document.getElementById( 'crq-confirm-backup' );
	const confirmBeforeImage = document.getElementById( 'crq-confirm-before-image' );
	const confirmSubmit = document.getElementById( 'crq-confirm-submit' );
	const cancelButton = document.getElementById( 'crq-cancel' );
	const numbers = new Intl.NumberFormat( document.documentElement.lang || undefined );

	const progress = {
		panel: document.getElementById( 'crq-progress' ),
		heading: document.getElementById( 'crq-progress-heading' ),
		text: document.getElementById( 'crq-progress-text' ),
		percent: document.getElementById( 'crq-progress-percent' ),
		bar: document.getElementById( 'crq-progress-bar' ),
		fill: document.querySelector( '#crq-progress-bar .crq-bar-fill' ),
		scanned: document.getElementById( 'crq-stat-scanned' ),
		changed: document.getElementById( 'crq-stat-changed' ),
		changedLabel: document.getElementById( 'crq-stat-changed-label' ),
		replacements: document.getElementById( 'crq-stat-replacements' ),
		elapsed: document.getElementById( 'crq-stat-elapsed' ),
		remaining: document.getElementById( 'crq-stat-remaining' ),
		tables: document.getElementById( 'crq-progress-tables' ),
		tableCount: document.getElementById( 'crq-progress-table-count' ),
		startedAt: 0,
		startPercent: 0,
		timer: null,
		spoken: 0,
	};

	let cancelRequested = false;
	let dryRun = null;
	let dialogOpener = null;

	if ( form ) {
		initForm();
	}

	confirmBackup.addEventListener( 'change', () => {
		confirmSubmit.disabled = ! confirmBackup.checked;
	} );

	dialog.addEventListener( 'close', () => {
		if ( dialogOpener ) {
			dialogOpener.focus();
		}

		if ( 'confirm' === dialog.returnValue && confirmBackup.checked ) {
			execute( dryRun, confirmBeforeImage.checked );
		}
	} );

	cancelButton.addEventListener( 'click', () => {
		cancelRequested = true;
		cancelButton.disabled = true;
		progress.text.textContent = __( 'Stopping after the current batch…', 'cr-relocate-db' );
	} );

	if ( runner.dataset.job ) {
		showJob( JSON.parse( runner.dataset.job ) );
	}

	/* Form ---------------------------------------------------------------- */

	function initForm() {
		initPairs();
		initColumnMenus();
		initWizard();

		const filter = document.getElementById( 'crq-table-filter' );
		const count = document.getElementById( 'crq-table-count' );
		const none = document.getElementById( 'crq-table-none' );
		const rows = [ ...form.querySelectorAll( '.crq-picker-row' ) ];
		const tableBoxes = () => [ ...form.querySelectorAll( 'input[name="tables[]"]' ) ];

		const updateCount = () => {
			const boxes = tableBoxes();
			count.textContent = sprintf(
				/* translators: 1: selected tables, 2: all tables. */
				__( '%1$s of %2$s tables selected', 'cr-relocate-db' ),
				numbers.format( boxes.filter( ( box ) => box.checked ).length ),
				numbers.format( boxes.length )
			);
		};

		filter.addEventListener( 'input', () => {
			const term = filter.value.trim().toLowerCase();
			let visible = 0;

			rows.forEach( ( row ) => {
				const match = ! term || row.dataset.table.toLowerCase().includes( term );
				row.hidden = ! match;
				visible += match ? 1 : 0;
			} );

			// Open collapsed groups so matches are not hidden inside them.
			if ( term ) {
				form.querySelectorAll( '.crq-picker-group' ).forEach( ( group ) => {
					group.open = true;
				} );
			}

			none.hidden = visible > 0;
		} );

		form.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-crq-select]' );

			if ( ! button ) {
				return;
			}

			const mode = button.dataset.crqSelect;

			// Only the tables the filter shows, so "select all" after filtering does what it says.
			tableBoxes()
				.filter( ( box ) => ! box.closest( '.crq-picker-row' ).hidden )
				.forEach( ( box ) => {
					box.checked = 'all' === mode || ( 'core' === mode && 'core' === box.dataset.group );
				} );

			updateCount();
		} );

		form.addEventListener( 'change', ( event ) => {
			if ( 'tables[]' === event.target.name ) {
				updateCount();
			}

			if ( 'scope' === event.target.name ) {
				applyScope();
				updateCount();
			}

			if ( event.target.closest( '.crq-options' ) ) {
				updateAdvancedSummary();
			}

			const columns = event.target.closest( '.crq-columns' );
			if ( columns ) {
				columns.querySelector( '.crq-columns-selected' ).textContent = numbers.format(
					columns.querySelectorAll( 'input:checked' ).length
				);
			}
		} );

		form.addEventListener( 'submit', onSubmit );
		applyScope();
		updateCount();
		updateAdvancedSummary();
	}

	/**
	 * Steps 1 and 2 of the form, one at a time. Step 3 is the preview, which
	 * replaces the form once a dry run starts.
	 */
	function initWizard() {
		form.addEventListener( 'click', ( event ) => {
			if ( event.target.closest( '[data-crq-next]' ) ) {
				const invalid = [ ...wizardStep( 1 ).querySelectorAll( 'input' ) ].find( ( input ) => ! input.checkValidity() );

				if ( invalid ) {
					invalid.reportValidity();
					return;
				}

				showWizardStep( 2 );
			}

			if ( event.target.closest( '[data-crq-back]' ) ) {
				showWizardStep( 1 );
			}
		} );

		// Coming from the dashboard with the values filled in, step 1 is already done.
		if ( '2' === form.dataset.startStep ) {
			showWizardStep( 2, false );
		}
	}

	function wizardStep( number ) {
		return form.querySelector( `.crq-wizard-step[data-step="${ number }"]` );
	}

	/**
	 * @param {number}  number Step to show: 1 or 2.
	 * @param {boolean} focus  Whether to move focus to the step's heading.
	 */
	function showWizardStep( number, focus = true ) {
		form.hidden = false;
		form.querySelectorAll( '.crq-wizard-step' ).forEach( ( step ) => {
			step.hidden = Number( step.dataset.step ) !== number;
		} );

		if ( 2 === number ) {
			renderRecap();
		}

		setStep( number );

		if ( focus ) {
			wizardStep( number ).querySelector( '.crq-wizard-title' ).focus();
		}
	}

	/**
	 * Step 2 repeats what step 1 asked for, with a way back to change it.
	 */
	function renderRecap() {
		const pairs = pairsFrom( new FormData( form ) ).filter( ( pair ) => pair.search );
		const edit = el( 'button', { type: 'button', className: 'crq-link-button' }, __( 'Change', 'cr-relocate-db' ) );
		edit.dataset.crqBack = '';

		document.getElementById( 'crq-recap' ).replaceChildren(
			el( 'span', { className: 'crq-recap-label' }, __( 'Replacing', 'cr-relocate-db' ) ),
			el(
				'ul',
				{ className: 'crq-pair-list' },
				...pairs.map( ( pair ) => el(
					'li',
					{},
					el( 'code', {}, pair.search ),
					el( 'span', { ariaHidden: 'true' }, ' → ' ),
					el( 'span', { className: 'screen-reader-text' }, __( 'replaced with', 'cr-relocate-db' ) ),
					pair.replace ? el( 'code', {}, pair.replace ) : el( 'em', {}, __( '(removed)', 'cr-relocate-db' ) )
				) )
			),
			edit
		);
	}

	/**
	 * "All WordPress tables" selects exactly the prefixed tables with every
	 * column; "Let me choose" opens the list to change that.
	 */
	function applyScope() {
		const scope = form.querySelector( 'input[name="scope"]:checked' );
		const custom = ! scope || 'custom' === scope.value;

		document.getElementById( 'crq-picker-panel' ).hidden = ! custom;

		if ( custom ) {
			return;
		}

		form.querySelectorAll( 'input[name="tables[]"]' ).forEach( ( box ) => {
			box.checked = 'core' === box.dataset.group;
		} );

		form.querySelectorAll( '.crq-columns' ).forEach( ( columns ) => {
			const boxes = columns.querySelectorAll( 'input' );
			boxes.forEach( ( box ) => {
				box.checked = true;
			} );
			columns.querySelector( '.crq-columns-selected' ).textContent = numbers.format( boxes.length );
		} );
	}

	/**
	 * The options that are on, next to the folded "Advanced options" heading.
	 */
	function updateAdvancedSummary() {
		const on = [ ...form.querySelectorAll( '.crq-options input:checked' ) ].map( ( box ) => box.dataset.short );

		document.getElementById( 'crq-advanced-summary' ).textContent = on.length ? `· ${ on.join( ', ' ) }` : '';
	}

	/**
	 * Column dropdowns in the table picker: one open at a time, closed by
	 * Escape or a click elsewhere.
	 */
	function initColumnMenus() {
		const menus = () => [ ...form.querySelectorAll( '.crq-columns[open]' ) ];

		form.addEventListener( 'toggle', ( event ) => {
			if ( event.target.matches( '.crq-columns' ) && event.target.open ) {
				menus().filter( ( menu ) => menu !== event.target ).forEach( ( menu ) => {
					menu.open = false;
				} );
			}
		}, true );

		document.addEventListener( 'click', ( event ) => {
			menus().filter( ( menu ) => ! menu.contains( event.target ) ).forEach( ( menu ) => {
				menu.open = false;
			} );
		} );

		document.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' !== event.key ) {
				return;
			}

			menus().forEach( ( menu ) => {
				menu.open = false;
				if ( menu.contains( document.activeElement ) ) {
					menu.querySelector( 'summary' ).focus();
				}
			} );
		} );
	}

	/**
	 * Marks the stepper at the top of Search & Replace.
	 *
	 * @param {number} current Step in progress (1 choose, 2 preview, 3 apply), or 4 when everything is done.
	 */
	function setStep( current ) {
		const steps = document.querySelectorAll( '#crq-steps li' );

		steps.forEach( ( step, index ) => {
			const number = index + 1;

			step.classList.toggle( 'is-done', number < current );
			step.classList.toggle( 'is-current', number === current );

			if ( number === current ) {
				step.setAttribute( 'aria-current', 'step' );
			} else {
				step.removeAttribute( 'aria-current' );
			}
		} );
	}

	/**
	 * The "Add another" button and the rows it creates. The limit comes from the
	 * server (data-max), which enforces it again when the job is created.
	 */
	function initPairs() {
		const list = document.getElementById( 'crq-pairs' );
		const template = document.getElementById( 'crq-pair-template' );
		const add = document.getElementById( 'crq-add-pair' );
		const count = document.getElementById( 'crq-pairs-count' );
		const max = Number( list.dataset.max ) || 1;

		const renumber = () => {
			const rows = [ ...list.children ];

			rows.forEach( ( row, index ) => {
				const number = index + 1;
				const search = row.querySelector( 'input[name="search[]"]' );
				const replace = row.querySelector( 'input[name="replace[]"]' );

				search.id = `crq-search-${ number }`;
				replace.id = `crq-replace-${ number }`;

				if ( index > 0 ) {
					const [ searchLabel, replaceLabel ] = row.querySelectorAll( 'label' );
					const remove = row.querySelector( '.crq-pair-remove' );
					searchLabel.htmlFor = search.id;
					replaceLabel.htmlFor = replace.id;
					/* translators: %d: pair number. */
					searchLabel.textContent = sprintf( __( 'Find, pair %d', 'cr-relocate-db' ), number );
					/* translators: %d: pair number. */
					replaceLabel.textContent = sprintf( __( 'Replace with, pair %d', 'cr-relocate-db' ), number );
					remove.querySelector( '.crq-pair-remove-label' ).textContent = __( 'Remove', 'cr-relocate-db' );
					/* translators: %d: pair number. */
					remove.setAttribute( 'aria-label', sprintf( __( 'Remove pair %d', 'cr-relocate-db' ), number ) );
				}
			} );

			add.disabled = rows.length >= max;
			count.textContent = sprintf(
				/* translators: 1: pairs in use, 2: maximum pairs. */
				__( '%1$s of %2$s', 'cr-relocate-db' ),
				numbers.format( rows.length ),
				numbers.format( max )
			);
		};

		add.addEventListener( 'click', () => {
			if ( list.children.length >= max ) {
				return;
			}

			list.append( template.content.cloneNode( true ) );
			renumber();
			list.lastElementChild.querySelector( 'input' ).focus();
		} );

		list.addEventListener( 'click', ( event ) => {
			const remove = event.target.closest( '.crq-pair-remove' );

			if ( ! remove ) {
				return;
			}

			const row = remove.closest( '.crq-pair' );
			const previous = row.previousElementSibling;

			row.remove();
			renumber();
			( previous ? previous.querySelector( 'input' ) : add ).focus();
			speak( __( 'Pair removed.', 'cr-relocate-db' ) );
		} );

		renumber();
	}

	async function onSubmit( event ) {
		event.preventDefault();

		clearNotices();
		applyScope();

		const data = new FormData( form );
		const tables = data.getAll( 'tables[]' );

		if ( ! tables.length ) {
			showNotice( 'error', __( 'Select at least one table to search.', 'cr-relocate-db' ) );
			return;
		}

		results.hidden = true;
		results.replaceChildren();
		setBusy( true );

		let job;
		try {
			job = await apiFetch( {
				path: API,
				method: 'POST',
				data: {
					pairs: pairsFrom( data ),
					case_sensitive: ! data.has( 'case_insensitive' ),
					whole_words: data.has( 'whole_words' ),
					url_variants: data.has( 'url_variants' ),
					skip_guids: data.has( 'skip_guids' ),
					tables,
					exclude_columns: excludedColumns( tables ),
				},
			} );
		} catch ( error ) {
			setBusy( false );
			showNotice( 'error', errorMessage( error ) );
			return;
		}

		form.hidden = true;
		speak( __( 'Dry run started.', 'cr-relocate-db' ) );
		run( job );
	}

	/**
	 * @param {FormData} data
	 * @return {Object[]} Search and replacement pairs, skipping added rows left completely empty.
	 */
	function pairsFrom( data ) {
		const replaces = data.getAll( 'replace[]' );

		return data.getAll( 'search[]' )
			.map( ( search, index ) => ( { search, replace: replaces[ index ] || '' } ) )
			.filter( ( pair, index ) => 0 === index || pair.search || pair.replace );
	}

	/**
	 * Unticked columns of the selected tables.
	 *
	 * @param {string[]} tables Selected tables.
	 * @return {Object<string, string[]>} Table => columns to leave out.
	 */
	function excludedColumns( tables ) {
		const excluded = {};

		tables.forEach( ( table ) => {
			const row = form.querySelector( `.crq-picker-row[data-table="${ CSS.escape( table ) }"]` );
			const off = row ? [ ...row.querySelectorAll( '.crq-columns input:not(:checked)' ) ].map( ( box ) => box.value ) : [];

			if ( off.length ) {
				excluded[ table ] = off;
			}
		} );

		return excluded;
	}

	/* Running a job ------------------------------------------------------- */

	/**
	 * A job embedded in the page: its results if it has finished, otherwise an
	 * offer to carry on from where it stopped.
	 *
	 * @param {Object} job Job as returned by the REST API.
	 */
	function showJob( job ) {
		if ( job.finished ) {
			if ( job.dry_run ) {
				dryRun = job;
				renderDryRun( job, false );
			} else {
				renderLiveRun( job, false );
			}
			return;
		}

		const message = job.interrupted
			? __( 'This job stopped before it finished, probably because the page running it was closed. Continuing carries on from the last completed batch.', 'cr-relocate-db' )
			: __( 'This job is still running, possibly in another tab. If that tab was closed, continue it here.', 'cr-relocate-db' );

		showNotice( 'warning', message, () => run( job ), __( 'Continue', 'cr-relocate-db' ), false );
	}

	/**
	 * Drives a job to the end. On a failed request the job is still safe on the
	 * server, so the notice offers to carry on from where it stopped.
	 *
	 * @param {Object} job Job as returned by the REST API.
	 */
	async function run( job ) {
		cancelRequested = false;
		setStep( 3 );
		startProgress( job );
		setBusy( true, ! job.dry_run );

		try {
			while ( ! job.finished ) {
				job = await step( job );
				updateProgress( job );
			}
		} catch ( error ) {
			stopProgress( 'stopped' );
			setBusy( false );
			showNotice( 'error', errorMessage( error ), () => run( job ) );
			return;
		}

		stopProgress( 'completed' === job.status ? 'done' : 'stopped' );
		setBusy( false );

		// Let the bar reach its final state before the results take over.
		await pause( 500 );
		progress.panel.hidden = true;

		if ( job.dry_run ) {
			dryRun = job;
			renderDryRun( job );
		} else {
			renderLiveRun( job );
			setStep( 'completed' === job.status ? 4 : 3 );
		}
	}

	/**
	 * Sends one run (or cancel) request, retrying a few times when the failure
	 * is likely to pass. Repeating a step is safe: the server works from the
	 * job's last saved position, under a lock.
	 *
	 * @param {Object} job Job as returned by the REST API.
	 * @return {Promise<Object>} The job after the step.
	 */
	async function step( job ) {
		for ( let attempt = 0; ; attempt++ ) {
			try {
				return await apiFetch( {
					path: `${ API }/${ job.id }/${ cancelRequested ? 'cancel' : 'run' }`,
					method: 'POST',
				} );
			} catch ( error ) {
				if ( attempt >= RETRY_DELAYS.length || ! isTransient( error ) ) {
					throw error;
				}

				progress.text.textContent = __( 'The connection was interrupted. Trying again…', 'cr-relocate-db' );
				await new Promise( ( resolve ) => setTimeout( resolve, RETRY_DELAYS[ attempt ] ) );
			}
		}
	}

	/**
	 * No response, a non-JSON error page (as proxies return on a timeout),
	 * a server error, or the job still locked by a step that is finishing.
	 *
	 * @param {Object} error Rejection from apiFetch.
	 * @return {boolean} Whether retrying may help.
	 */
	function isTransient( error ) {
		const code = error && error.code;
		const status = error && error.data && error.data.status;

		return ! code
			|| 'fetch_error' === code
			|| 'invalid_json' === code
			|| 'crq_relocate_job_busy' === code
			|| status >= 500;
	}

	async function execute( job, beforeImage ) {
		clearNotices();

		let live;
		try {
			live = await apiFetch( {
				path: `${ API }/${ job.id }/execute`,
				method: 'POST',
				data: { confirmed: true, before_image: beforeImage },
			} );
		} catch ( error ) {
			showNotice( 'error', errorMessage( error ) );
			return;
		}

		results.hidden = true;
		speak( __( 'Replacement started.', 'cr-relocate-db' ) );
		run( live );
	}

	async function resume( job ) {
		clearNotices();

		try {
			job = await apiFetch( { path: `${ API }/${ job.id }/resume`, method: 'POST' } );
		} catch ( error ) {
			showNotice( 'error', errorMessage( error ) );
			return;
		}

		results.hidden = true;
		run( job );
	}

	/* Progress ------------------------------------------------------------ */

	function startProgress( job ) {
		progress.panel.hidden = false;
		progress.heading.textContent = job.dry_run
			? __( 'Dry run in progress', 'cr-relocate-db' )
			: __( 'Replacing in the database', 'cr-relocate-db' );
		progress.changedLabel.textContent = job.dry_run
			? __( 'Rows to change', 'cr-relocate-db' )
			: __( 'Rows changed', 'cr-relocate-db' );
		progress.text.textContent = job.dry_run
			? __( 'Starting…', 'cr-relocate-db' )
			: __( 'Starting… Keep this page open until the replacement finishes.', 'cr-relocate-db' );

		cancelButton.disabled = false;
		cancelButton.textContent = job.dry_run ? __( 'Cancel', 'cr-relocate-db' ) : __( 'Stop after this batch', 'cr-relocate-db' );

		progress.bar.classList.remove( 'is-done', 'is-stopped' );
		progress.bar.classList.add( 'is-indeterminate' );
		progress.bar.removeAttribute( 'aria-valuetext' );
		progress.fill.style.width = '';
		progress.percent.textContent = `${ job.progress }%`;
		progress.remaining.textContent = '—';
		progress.startedAt = Date.now();
		progress.startPercent = job.progress;
		progress.spoken = 0;

		progress.tables.replaceChildren(
			...job.tables.map( ( table ) => el( 'li', {}, el( 'span', { className: 'dashicons dashicons-marker', ariaHidden: 'true' } ), table ) )
		);

		clearInterval( progress.timer );
		progress.timer = setInterval( tick, 1000 );
		tick();
		updateStats( job );
		updateTables( job );
		progress.panel.scrollIntoView( { behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' } );
	}

	function updateProgress( job ) {
		const percent = job.finished && 'completed' === job.status ? 100 : job.progress;

		progress.bar.classList.remove( 'is-indeterminate' );
		progress.fill.style.width = `${ percent }%`;
		progress.bar.setAttribute( 'aria-valuenow', String( percent ) );
		progress.percent.textContent = `${ percent }%`;

		if ( ! cancelRequested && ! job.finished ) {
			progress.text.textContent = job.current_table
				? sprintf(
					/* translators: 1: table name, 2: table number, 3: number of tables. */
					__( 'Working on %1$s (table %2$s of %3$s)', 'cr-relocate-db' ),
					job.current_table,
					numbers.format( job.tables_done + 1 ),
					numbers.format( job.tables_total )
				)
				: __( 'Finishing…', 'cr-relocate-db' );
		}

		progress.bar.setAttribute( 'aria-valuetext', `${ percent }% – ${ progress.text.textContent }` );

		updateStats( job );
		updateTables( job );
		updateRemaining( percent );

		// Screen readers hear every quarter, not every batch.
		const quarter = Math.floor( percent / 25 ) * 25;
		if ( quarter > progress.spoken && percent < 100 ) {
			progress.spoken = quarter;
			/* translators: %s: percentage. */
			speak( sprintf( __( '%s percent done.', 'cr-relocate-db' ), quarter ) );
		}
	}

	function stopProgress( state ) {
		clearInterval( progress.timer );
		progress.bar.classList.remove( 'is-indeterminate' );
		progress.bar.classList.add( 'done' === state ? 'is-done' : 'is-stopped' );
		progress.remaining.textContent = '—';

		if ( 'done' === state ) {
			progress.fill.style.width = '100%';
			progress.percent.textContent = '100%';
			progress.text.textContent = __( 'Done.', 'cr-relocate-db' );
		}
	}

	function updateStats( job ) {
		progress.scanned.textContent = numbers.format( job.totals.rows_scanned );
		progress.changed.textContent = numbers.format( job.totals.rows_changed );
		progress.replacements.textContent = numbers.format( job.totals.replacements );
	}

	function updateTables( job ) {
		const items = [ ...progress.tables.children ];

		items.forEach( ( item, index ) => {
			const state = index < job.tables_done ? 'done' : ( index === job.tables_done && ! job.finished ? 'current' : 'pending' );
			const icon = item.querySelector( '.dashicons' );

			item.className = `is-${ state }`;
			icon.className = `dashicons dashicons-${ { done: 'yes-alt', current: 'update', pending: 'marker' }[ state ] }`;
		} );

		progress.tableCount.textContent = `(${ numbers.format( Math.min( job.tables_done, job.tables_total ) ) } / ${ numbers.format( job.tables_total ) })`;

		const current = progress.tables.querySelector( '.is-current' );
		if ( current && progress.tables.closest( 'details' ).open ) {
			current.scrollIntoView( { block: 'nearest' } );
		}
	}

	function updateRemaining( percent ) {
		const elapsed = ( Date.now() - progress.startedAt ) / 1000;
		const done = percent - progress.startPercent;

		// Estimates are wild at the very start, so wait for a little data first.
		if ( done < 3 || elapsed < 5 || percent >= 100 ) {
			progress.remaining.textContent = percent >= 100 ? '0:00' : '—';
			return;
		}

		progress.remaining.textContent = sprintf(
			/* translators: %s: time, e.g. 2:15. */
			__( 'about %s', 'cr-relocate-db' ),
			clock( ( elapsed / done ) * ( 100 - percent ) )
		);
	}

	function tick() {
		progress.elapsed.textContent = clock( ( Date.now() - progress.startedAt ) / 1000 );
	}

	/* Results ------------------------------------------------------------- */

	function renderDryRun( job, announce = true ) {
		const heading = startResults( __( 'Preview', 'cr-relocate-db' ), __( 'Change search', 'cr-relocate-db' ) );

		if ( 'completed' === job.status ) {
			results.append( status( 'success', 'yes-alt', __( 'Dry run complete. Nothing in the database was changed.', 'cr-relocate-db' ) ) );
		} else if ( 'cancelled' === job.status ) {
			results.append( status( 'warning', 'dismiss', __( 'Dry run cancelled. The figures below cover only the part that was searched.', 'cr-relocate-db' ) ) );
		} else {
			results.append( status( 'error', 'warning', job.error || __( 'The dry run stopped because of an error.', 'cr-relocate-db' ) ) );
			results.append( resumeButton( job ) );
		}

		results.append(
			tiles( [
				[ 'edit', __( 'Rows that would change', 'cr-relocate-db' ), job.totals.rows_changed ],
				[ 'update', __( 'Replacements', 'cr-relocate-db' ), job.totals.replacements ],
				[ 'shield', __( 'Left unchanged', 'cr-relocate-db' ), job.totals.skipped ],
			] ),
			scanned( job )
		);

		// The next step comes straight after the totals; the examples below back it up.
		if ( job.executable ) {
			results.append( applySection( job ) );
		} else if ( 'completed' === job.status && ! job.totals.rows_changed ) {
			results.append( el( 'p', { className: 'crq-empty' }, __( 'No matches were found, so there is nothing to replace.', 'cr-relocate-db' ) ) );
		}

		appendSamples( job, __( 'What will change', 'cr-relocate-db' ) );

		appendReport( job, __( 'Rows to change', 'cr-relocate-db' ) );

		finishResults( heading, announce, sprintf(
			/* translators: 1: number of replacements, 2: number of rows. */
			_n(
				'Dry run finished: %1$s replacement in %2$s rows.',
				'Dry run finished: %1$s replacements in %2$s rows.',
				job.totals.replacements,
				'cr-relocate-db'
			),
			numbers.format( job.totals.replacements ),
			numbers.format( job.totals.rows_changed )
		) );
	}

	function renderLiveRun( job, announce = true ) {
		const heading = startResults( __( 'Replacement results', 'cr-relocate-db' ), __( 'Start another search', 'cr-relocate-db' ) );
		let message;

		if ( 'completed' === job.status ) {
			message = __( 'Replacement complete. The changes below have been written to the database.', 'cr-relocate-db' );
			results.append( status( 'success', 'yes-alt', message ) );
		} else if ( 'cancelled' === job.status ) {
			message = __( 'Replacement stopped. Batches finished before stopping were written to the database; the rest was not touched.', 'cr-relocate-db' );
			results.append( status( 'warning', 'dismiss', message ) );
		} else {
			message = job.error || __( 'The replacement stopped because of an error.', 'cr-relocate-db' );
			results.append( status( 'error', 'warning', message ) );
			results.append(
				el( 'p', {}, __( 'Batches finished before the error were written to the database. The batch that failed was rolled back completely. Resuming carries on from there.', 'cr-relocate-db' ) ),
				resumeButton( job )
			);
		}

		if ( job.site_address_changed ) {
			results.append(
				el(
					'div',
					{ className: 'notice notice-warning inline' },
					el( 'p', {}, __( 'The site address (siteurl and home) was changed, so you will need to log in again at the new address.', 'cr-relocate-db' ) ),
					el( 'p', {}, el( 'a', { href: job.login_url }, __( 'Log in at the new address', 'cr-relocate-db' ) ) )
				)
			);
		}

		results.append(
			tiles( [
				[ 'edit', __( 'Rows changed', 'cr-relocate-db' ), job.totals.rows_changed ],
				[ 'update', __( 'Replacements', 'cr-relocate-db' ), job.totals.replacements ],
				[ 'shield', __( 'Left unchanged', 'cr-relocate-db' ), job.totals.skipped ],
			] ),
			scanned( job )
		);

		if ( job.before_image_url ) {
			results.append(
				el(
					'section',
					{ className: 'crq-card' },
					el( 'h3', { className: 'crq-card-title' }, __( 'Original values', 'cr-relocate-db' ) ),
					el( 'p', {}, __( 'Importing this file into the database puts every changed value back as it was, overwriting any edits made to those values since.', 'cr-relocate-db' ) ),
					el(
						'p',
						{},
						el(
							'a',
							{ className: 'button button-primary', href: job.before_image_url },
							el( 'span', { className: 'dashicons dashicons-download', ariaHidden: 'true' } ),
							' ',
							__( 'Download original values (.sql.gz)', 'cr-relocate-db' )
						)
					)
				)
			);
		}

		appendSamples( job, __( 'What changed', 'cr-relocate-db' ) );
		appendReport( job, __( 'Rows changed', 'cr-relocate-db' ) );
		finishResults( heading, announce, message );
	}

	function applySection( job ) {
		const button = el(
			'button',
			{ type: 'button', className: 'button button-primary crq-button-lg' },
			el( 'span', { className: 'dashicons dashicons-update', ariaHidden: 'true' } ),
			__( 'Replace in database…', 'cr-relocate-db' )
		);

		button.addEventListener( 'click', () => openDialog( job, button ) );

		return el(
			'section',
			{ className: 'crq-card crq-apply' },
			el(
				'div',
				{},
				el( 'h3', { className: 'crq-card-title' }, __( 'Happy with the preview?', 'cr-relocate-db' ) ),
				el(
					'p',
					{},
					__( 'Write exactly these changes to the database. You confirm once more first, and can save the original values to a file.', 'cr-relocate-db' )
				)
			),
			button
		);
	}

	function appendReport( job, changedLabel ) {
		if ( job.totals.skipped ) {
			results.append(
				el(
					'div',
					{ className: 'notice notice-info inline' },
					el( 'p', {}, __( 'Some matching values are left unchanged because changing them could corrupt data. Open “Results per table” below to see where and why.', 'cr-relocate-db' ) )
				)
			);
		}

		if ( ! job.report ) {
			return;
		}

		// The detail, folded away: the totals and examples above answer most questions.
		results.append(
			el(
				'details',
				{ className: 'crq-card crq-disclosure' },
				el(
					'summary',
					{},
					el( 'span', { className: 'crq-card-title' }, __( 'Results per table', 'cr-relocate-db' ) ),
					el(
						'span',
						{ className: 'crq-disclosure-count' },
						/* translators: %s: number of tables. */
						sprintf( _n( '%s table', '%s tables', job.report.tables.length, 'cr-relocate-db' ), numbers.format( job.report.tables.length ) )
					)
				),
				tablesReport( job.report.tables, changedLabel )
			)
		);
	}

	/**
	 * @param {Object} job   Finished job.
	 * @param {string} title Heading for the examples.
	 */
	function appendSamples( job, title ) {
		if ( job.report && job.report.samples.length ) {
			results.append( samples( job.report.samples, title ) );
		}
	}

	/**
	 * @param {Object} job Finished job.
	 * @return {HTMLElement} One line with what was searched.
	 */
	function scanned( job ) {
		return el(
			'p',
			{ className: 'crq-results-meta' },
			sprintf(
				/* translators: 1: number of rows, 2: number of tables. */
				_n( 'Searched %1$s rows in %2$s table.', 'Searched %1$s rows in %2$s tables.', job.tables_done, 'cr-relocate-db' ),
				numbers.format( job.totals.rows_scanned ),
				numbers.format( job.tables_done )
			)
		);
	}

	/**
	 * The per-table results with sorting, filtering and paging, all in the
	 * browser: the report is already complete once a job has finished.
	 *
	 * @param {Object[]} tables       Per-table results.
	 * @param {string}   changedLabel Heading of the rows-changed column.
	 * @return {HTMLElement} The table and its controls.
	 */
	function tablesReport( tables, changedLabel ) {
		const columns = [
			{ key: 'name', label: __( 'Table', 'cr-relocate-db' ), numeric: false },
			{ key: 'rows_scanned', label: __( 'Rows scanned', 'cr-relocate-db' ), numeric: true },
			{ key: 'rows_changed', label: changedLabel, numeric: true },
			{ key: 'replacements', label: __( 'Replacements', 'cr-relocate-db' ), numeric: true },
		];
		const state = { sort: 'replacements', direction: 'desc', term: '', changedOnly: tables.some( ( table ) => table.rows_changed ), page: 1 };

		const filter = el( 'input', { type: 'search', className: 'crq-filter', placeholder: __( 'Filter tables…', 'cr-relocate-db' ) } );
		filter.setAttribute( 'aria-label', __( 'Filter tables', 'cr-relocate-db' ) );
		const changedOnly = el( 'input', { type: 'checkbox', checked: state.changedOnly } );
		const caption = el( 'caption', { className: 'screen-reader-text' } );
		const headRow = el( 'tr' );
		const body = el( 'tbody' );
		const pager = el( 'div', { className: 'crq-pager' } );
		const announcer = el( 'span', { className: 'screen-reader-text', role: 'status' } );

		columns.forEach( ( column ) => {
			const button = el( 'button', { type: 'button', className: 'crq-sort' }, column.label, el( 'span', { className: 'dashicons', ariaHidden: 'true' } ) );
			button.addEventListener( 'click', () => {
				state.direction = state.sort === column.key && 'desc' === state.direction ? 'asc' : ( state.sort === column.key ? 'desc' : ( column.numeric ? 'desc' : 'asc' ) );
				state.sort = column.key;
				render();
			} );
			headRow.append( el( 'th', { scope: 'col', className: column.numeric ? 'num' : '' }, button ) );
		} );
		headRow.append(
			el( 'th', { scope: 'col' }, __( 'Columns', 'cr-relocate-db' ) ),
			el( 'th', { scope: 'col' }, __( 'Notes', 'cr-relocate-db' ) )
		);

		// Header buttons are hidden when the table is stacked on small screens; this takes their place.
		const sortSelect = el( 'select', { className: 'crq-sort-select' } );
		sortSelect.setAttribute( 'aria-label', __( 'Sort tables by', 'cr-relocate-db' ) );
		columns.forEach( ( column ) => {
			/* translators: %s: column name. */
			sortSelect.append( el( 'option', { value: column.key }, sprintf( __( 'Sort by %s', 'cr-relocate-db' ), column.label.toLowerCase() ) ) );
		} );
		sortSelect.addEventListener( 'change', () => {
			state.sort = sortSelect.value;
			state.direction = 'name' === state.sort ? 'asc' : 'desc';
			render();
		} );

		filter.addEventListener( 'input', () => {
			state.term = filter.value.trim().toLowerCase();
			state.page = 1;
			render();
		} );
		changedOnly.addEventListener( 'change', () => {
			state.changedOnly = changedOnly.checked;
			state.page = 1;
			render();
		} );

		function render() {
			sortSelect.value = state.sort;

			const rows = tables
				.filter( ( table ) => ( ! state.term || table.name.toLowerCase().includes( state.term ) ) && ( ! state.changedOnly || table.rows_changed || table.skipped.length || table.note ) )
				.sort( ( a, b ) => {
					const result = 'name' === state.sort ? a.name.localeCompare( b.name ) : a[ state.sort ] - b[ state.sort ];
					return 'asc' === state.direction ? result : -result;
				} );
			const pages = Math.max( 1, Math.ceil( rows.length / PAGE_SIZE ) );
			state.page = Math.min( state.page, pages );
			const shown = rows.slice( ( state.page - 1 ) * PAGE_SIZE, state.page * PAGE_SIZE );

			[ ...headRow.children ].slice( 0, columns.length ).forEach( ( th, index ) => {
				const active = columns[ index ].key === state.sort;
				const icon = th.querySelector( '.dashicons' );

				if ( active ) {
					th.setAttribute( 'aria-sort', 'asc' === state.direction ? 'ascending' : 'descending' );
				} else {
					th.removeAttribute( 'aria-sort' );
				}
				icon.className = `dashicons dashicons-arrow-${ active && 'asc' === state.direction ? 'up' : 'down' }`;
			} );

			body.replaceChildren(
				...( shown.length ? shown.map( ( table ) => tableRow( table, columns ) ) : [ el( 'tr', {}, el( 'td', { colSpan: columns.length + 2, className: 'crq-empty' }, __( 'No tables match.', 'cr-relocate-db' ) ) ) ] )
			);

			caption.textContent = __( 'Results per table', 'cr-relocate-db' );
			announcer.textContent = sprintf(
				/* translators: 1: tables shown, 2: tables matching. */
				__( 'Showing %1$s of %2$s tables.', 'cr-relocate-db' ),
				numbers.format( shown.length ),
				numbers.format( rows.length )
			);

			pager.replaceChildren();
			if ( pages > 1 ) {
				const previous = el( 'button', { type: 'button', className: 'button', disabled: 1 === state.page }, '‹ ', __( 'Previous', 'cr-relocate-db' ) );
				const next = el( 'button', { type: 'button', className: 'button', disabled: pages === state.page }, __( 'Next', 'cr-relocate-db' ), ' ›' );
				previous.addEventListener( 'click', () => {
					state.page--;
					render();
				} );
				next.addEventListener( 'click', () => {
					state.page++;
					render();
				} );
				pager.append(
					/* translators: 1: current page, 2: number of pages. */
					el( 'span', {}, sprintf( __( 'Page %1$s of %2$s', 'cr-relocate-db' ), numbers.format( state.page ), numbers.format( pages ) ) ),
					previous,
					next
				);
			}
		}

		render();

		return el(
			'div',
			{},
			el(
				'div',
				{ className: 'crq-table-tools' },
				filter,
				sortSelect,
				el( 'label', {}, changedOnly, __( 'Only tables with changes or notes', 'cr-relocate-db' ) ),
				announcer
			),
			el(
				'div',
				{ className: 'crq-table-scroll' },
				el( 'table', { className: 'widefat striped crq-tables crq-stack-table' }, caption, el( 'thead', {}, headRow ), body )
			),
			pager
		);
	}

	function tableRow( table, columns ) {
		const notes = [];

		if ( table.note ) {
			notes.push( table.note );
		}
		table.skipped.forEach( ( skipped ) => {
			notes.push( sprintf(
				/* translators: 1: number of values, 2: reason. */
				__( '%1$s left unchanged: %2$s', 'cr-relocate-db' ),
				numbers.format( skipped.count ),
				skipped.reason
			) );
		} );

		const changed = Object.entries( table.columns ).map( ( [ name, count ] ) => `${ name } (${ numbers.format( count ) })` );

		// data-label is shown next to each value when the table is stacked on small screens.
		const cell = ( label, value, className = '' ) => {
			const td = el( 'td', { className }, value );
			td.dataset.label = label;
			return td;
		};

		return el(
			'tr',
			{},
			el( 'th', { scope: 'row' }, el( 'code', {}, table.name ) ),
			cell( columns[ 1 ].label, numbers.format( table.rows_scanned ), 'num' ),
			cell( columns[ 2 ].label, numbers.format( table.rows_changed ), 'num' ),
			cell( columns[ 3 ].label, numbers.format( table.replacements ), 'num' ),
			cell( __( 'Columns', 'cr-relocate-db' ), changed.join( ', ' ) || '—' ),
			cell( __( 'Notes', 'cr-relocate-db' ), notes.join( ' ' ) || '—' )
		);
	}

	function samples( list, title ) {
		return el(
			'section',
			{ className: 'crq-card crq-samples' },
			el( 'h3', { className: 'crq-card-title' }, title ),
			el(
				'p',
				{ className: 'description' },
				sprintf(
					/* translators: %s: number of examples. */
					_n( '%s change found, with a little surrounding text.', 'The first %s changes found, with a little surrounding text.', list.length, 'cr-relocate-db' ),
					numbers.format( list.length )
				)
			),
			el(
				'ol',
				{},
				...list.map( ( sample ) => el(
					'li',
					{},
					el( 'p', {}, el( 'code', {}, `${ sample.table }.${ sample.column }` ), ' ', el( 'span', { className: 'description' }, sample.key ) ),
					el(
						'dl',
						{ className: 'crq-sample' },
						el( 'dt', {}, __( 'Before', 'cr-relocate-db' ) ),
						el( 'dd', {}, el( 'pre', {}, sample.before ) ),
						el( 'dt', {}, __( 'After', 'cr-relocate-db' ) ),
						el( 'dd', {}, el( 'pre', {}, sample.after ) )
					)
				) )
			)
		);
	}

	/* Confirmation -------------------------------------------------------- */

	function openDialog( job, opener ) {
		document.getElementById( 'crq-confirm-summary' ).replaceChildren(
			el(
				'p',
				{},
				sprintf(
					/* translators: 1: number of replacements, 2: number of rows, 3: number of tables. */
					__( '%1$s replacements in %2$s rows across %3$s tables will be written to the database.', 'cr-relocate-db' ),
					numbers.format( job.totals.replacements ),
					numbers.format( job.totals.rows_changed ),
					numbers.format( job.tables_total )
				)
			),
			el(
				'ul',
				{ className: 'crq-pair-list' },
				...job.pairs.map( ( pair ) => el(
					'li',
					{},
					el( 'code', {}, pair.search ),
					el( 'span', { ariaHidden: 'true' }, ' → ' ),
					el( 'span', { className: 'screen-reader-text' }, __( 'replaced with', 'cr-relocate-db' ) ),
					pair.replace ? el( 'code', {}, pair.replace ) : el( 'em', {}, __( '(nothing: removed)', 'cr-relocate-db' ) )
				) )
			)
		);

		document.getElementById( 'crq-confirm-warnings' ).replaceChildren( ...warnings( job ).map( ( text ) => el( 'li', {}, text ) ) );

		confirmBackup.checked = false;
		confirmSubmit.disabled = true;
		dialogOpener = opener;
		dialog.returnValue = '';
		dialog.showModal();
		confirmBackup.focus();
	}

	function warnings( job ) {
		const list = [];
		const untransactional = job.untransactional_tables || [];

		if ( untransactional.length ) {
			list.push( sprintf(
				/* translators: %s: comma-separated table names. */
				__( 'These tables do not support transactions, so if the replacement is interrupted a batch in them could be left partly written: %s', 'cr-relocate-db' ),
				untransactional.join( ', ' )
			) );
		}

		if ( job.pairs.some( ( pair ) => pair.replace.includes( pair.search ) ) ) {
			list.push( __( 'The replacement contains the search text. Running this same replacement a second time would apply it again.', 'cr-relocate-db' ) );
		}

		if ( job.touches_site_address ) {
			list.push( __( 'If this changes the site address (siteurl or home), it is changed last and you will need to log in again at the new address.', 'cr-relocate-db' ) );
		}

		return list;
	}

	/* Helpers ------------------------------------------------------------- */

	/**
	 * @param {string}  title  Heading of the results.
	 * @param {string=} action On Search & Replace, a button back to the form with this label.
	 * @return {HTMLElement} The heading.
	 */
	function startResults( title, action ) {
		const heading = el( 'h2', { tabIndex: -1, className: 'crq-title' }, title );
		const head = el( 'div', { className: 'crq-results-head' }, heading );

		if ( form && action ) {
			const back = el( 'button', { type: 'button', className: 'button' }, el( 'span', { ariaHidden: 'true' }, '← ' ), action );
			back.addEventListener( 'click', () => {
				results.hidden = true;
				clearNotices();
				showWizardStep( 1 );
			} );
			head.append( back );
		}

		results.replaceChildren( head );
		return heading;
	}

	/**
	 * @param {HTMLElement} heading
	 * @param {boolean}     announce     False when showing a job on page load: moving focus then would be jarring.
	 * @param {string}      announcement Text for screen readers.
	 */
	function finishResults( heading, announce, announcement ) {
		results.hidden = false;

		if ( announce ) {
			heading.focus();
			speak( announcement );
		}
	}

	function tiles( items ) {
		return el(
			'ul',
			{ className: 'crq-tiles' },
			...items.map( ( [ icon, label, value ] ) => el(
				'li',
				{ className: 'crq-tile' },
				el( 'span', { className: `crq-tile-icon dashicons dashicons-${ icon }`, ariaHidden: 'true' } ),
				el( 'span', { className: 'crq-tile-value' }, numbers.format( value ) ),
				el( 'span', { className: 'crq-tile-label' }, label )
			) )
		);
	}

	function resumeButton( job ) {
		const button = el( 'button', { type: 'button', className: 'button button-primary' }, __( 'Resume', 'cr-relocate-db' ) );
		button.addEventListener( 'click', () => resume( job ) );
		return el( 'p', {}, button );
	}

	function status( type, icon, text ) {
		return el(
			'p',
			{ className: `crq-status crq-status-${ type }` },
			el( 'span', { className: `dashicons dashicons-${ icon }`, ariaHidden: 'true' } ),
			text
		);
	}

	/**
	 * @param {string}    type     Notice type: error, warning, success or info.
	 * @param {string}    message  Message text.
	 * @param {Function=} retry    When given, a button to carry on the job.
	 * @param {string=}   label    Label for that button.
	 * @param {boolean=}  announce Whether to read the message out straight away.
	 */
	function showNotice( type, message, retry, label, announce = true ) {
		const notice = el( 'div', { className: `notice notice-${ type }` }, el( 'p', {}, message ) );

		if ( retry ) {
			const button = el( 'button', { type: 'button', className: 'button' }, label || __( 'Resume', 'cr-relocate-db' ) );
			button.addEventListener( 'click', () => {
				notice.remove();
				retry();
			} );
			notice.append( el( 'p', {}, button ) );
		}

		notices.replaceChildren( notice );

		if ( announce ) {
			speak( message, 'assertive' );
		}
	}

	function clearNotices() {
		notices.replaceChildren();
	}

	function warnBeforeLeaving( event ) {
		event.preventDefault();
		event.returnValue = '';
	}

	/**
	 * @param {boolean} busy
	 * @param {boolean} live Whether a live replacement is running, which makes leaving the page worth a warning.
	 */
	function setBusy( busy, live = false ) {
		runner.setAttribute( 'aria-busy', busy ? 'true' : 'false' );

		if ( submitButton ) {
			submitButton.disabled = busy;
		}

		if ( busy && live ) {
			window.addEventListener( 'beforeunload', warnBeforeLeaving );
		} else {
			window.removeEventListener( 'beforeunload', warnBeforeLeaving );
		}
	}

	function errorMessage( error ) {
		return ( error && error.message ) || __( 'The request failed. Check your connection and try again.', 'cr-relocate-db' );
	}

	function clock( seconds ) {
		const total = Math.max( 0, Math.round( seconds ) );
		const hours = Math.floor( total / 3600 );
		const minutes = Math.floor( ( total % 3600 ) / 60 );
		const rest = String( total % 60 ).padStart( 2, '0' );

		return hours ? `${ hours }:${ String( minutes ).padStart( 2, '0' ) }:${ rest }` : `${ minutes }:${ rest }`;
	}

	function prefersReducedMotion() {
		return window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	function pause( ms ) {
		return new Promise( ( resolve ) => setTimeout( resolve, prefersReducedMotion() ? 0 : ms ) );
	}

	/**
	 * Small element builder. Children are strings (added as text) or nodes.
	 *
	 * @param {string}           tag
	 * @param {Object}           props    Properties set directly on the element.
	 * @param {...(string|Node)} children
	 * @return {HTMLElement} The element.
	 */
	function el( tag, props = {}, ...children ) {
		const element = Object.assign( document.createElement( tag ), props );
		element.append( ...children.map( ( child ) => ( 'number' === typeof child ? String( child ) : child ) ) );
		return element;
	}
}() );
