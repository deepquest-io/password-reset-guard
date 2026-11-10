/**
 * Password Reset Guard — admin screen behaviour.
 */

( function () {
	'use strict';

	var cfg = window.pwrgAdmin || {};
	var i18n = cfg.i18n || {};

	var state = {
		sort: 'last_seen',
		dir: 'DESC',
		page: 1,
		vpn: false,
		tor: false
	};

	var rowsBody = null;
	var pagination = null;

	function $( id ) {
		return document.getElementById( id );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text && null !== text ) {
			node.textContent = text;
		}

		return node;
	}

	function filters() {
		return {
			filter_username: ( $( 'pwrg-f-username' ) || {} ).value || '',
			filter_user_id: ( $( 'pwrg-f-user-id' ) || {} ).value || '',
			filter_ip: ( $( 'pwrg-f-ip' ) || {} ).value || '',
			filter_country: ( ( $( 'pwrg-f-country' ) || {} ).value || '' ).toUpperCase()
		};
	}

	function requestParams() {
		var f = filters();
		var params = {
			action: 'pwrg_logs',
			nonce: cfg.nonce,
			sort: state.sort,
			dir: state.dir,
			page: state.page
		};

		[ 'filter_username', 'filter_user_id', 'filter_ip', 'filter_country' ].forEach( function ( key ) {
			if ( f[ key ] ) {
				params[ key ] = f[ key ];
			}
		} );

		if ( state.vpn ) {
			params.filter_vpn = 1;
		}

		if ( state.tor ) {
			params.filter_tor = 1;
		}

		return params;
	}

	function get( action, extra ) {
		var params = new URLSearchParams( Object.assign( { action: action, nonce: cfg.nonce }, extra || {} ) );
		return fetch( cfg.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	function tag( on, label, onClass ) {
		return el( 'span', on ? onClass : 'pwrg-tag-clean', on ? label : i18n.clean );
	}

	function render( data ) {
		rowsBody.textContent = '';

		if ( ! data.rows || ! data.rows.length ) {
			var empty = el( 'tr' );
			var cell = el( 'td', 'pwrg-empty', i18n.empty );
			cell.colSpan = 8;
			empty.appendChild( cell );
			rowsBody.appendChild( empty );
			pagination.textContent = '';
			return;
		}

		data.rows.forEach( function ( row ) {
			var tr = el( 'tr' );

			tr.appendChild( el( 'td', 'pwrg-strong', row.username ) );
			tr.appendChild( el( 'td', null, row.user_id ) );
			tr.appendChild( el( 'td', 'pwrg-mono', row.ip_address ) );

			var country = el( 'td' );
			if ( row.flag ) {
				country.appendChild( el( 'span', 'pwrg-flag', row.flag ) );
			}
			country.appendChild( document.createTextNode( row.country || '—' ) );
			tr.appendChild( country );

			var vpnCell = el( 'td' );
			vpnCell.appendChild( tag( parseInt( row.is_vpn, 10 ), i18n.vpn, 'pwrg-tag-vpn' ) );
			tr.appendChild( vpnCell );

			var torCell = el( 'td' );
			torCell.appendChild( tag( parseInt( row.is_tor, 10 ), i18n.tor, 'pwrg-tag-tor' ) );
			tr.appendChild( torCell );

			tr.appendChild( el( 'td', 'pwrg-strong', row.reset_count ) );
			tr.appendChild( el( 'td', null, row.last_seen ) );

			rowsBody.appendChild( tr );
		} );

		renderPagination( data );
		renderSortMarks();
	}

	function renderPagination( data ) {
		pagination.textContent = '';

		if ( data.pages <= 1 ) {
			return;
		}

		var label = ( i18n.pageOf || 'Page %1$s of %2$s (%3$s rows)' )
			.replace( '%1$s', data.page )
			.replace( '%2$s', data.pages )
			.replace( '%3$s', data.total );

		pagination.appendChild( el( 'span', 'pwrg-page-label', label ) );

		if ( data.page > 1 ) {
			var prev = el( 'a', 'button', i18n.prev );
			prev.href = '#';
			prev.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				load( data.page - 1 );
			} );
			pagination.appendChild( prev );
		}

		if ( data.page < data.pages ) {
			var next = el( 'a', 'button', i18n.next );
			next.href = '#';
			next.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				load( data.page + 1 );
			} );
			pagination.appendChild( next );
		}
	}

	function renderSortMarks() {
		document.querySelectorAll( '.pwrg-sort' ).forEach( function ( th ) {
			th.classList.remove( 'pwrg-sorted-asc', 'pwrg-sorted-desc' );

			if ( th.dataset.col === state.sort ) {
				th.classList.add( state.dir === 'ASC' ? 'pwrg-sorted-asc' : 'pwrg-sorted-desc' );
			}
		} );
	}

	function loadStats() {
		get( 'pwrg_stats' ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				return;
			}

			Object.keys( json.data ).forEach( function ( key ) {
				var target = document.querySelector( '[data-stat="' + key + '"]' );
				if ( target ) {
					target.textContent = json.data[ key ];
				}
			} );
		} );
	}

	function load( page ) {
		state.page = page || 1;

		get( 'pwrg_logs', requestParams() )
			.then( function ( json ) {
				if ( json && json.success ) {
					render( json.data );
					loadStats();
				} else {
					rowsBody.textContent = '';
					var tr = el( 'tr' );
					var td = el( 'td', 'pwrg-empty', i18n.error );
					td.colSpan = 8;
					tr.appendChild( td );
					rowsBody.appendChild( tr );
				}
			} )
			.catch( function () {
				rowsBody.textContent = '';
				var tr = el( 'tr' );
				var td = el( 'td', 'pwrg-empty', i18n.error );
				td.colSpan = 8;
				tr.appendChild( td );
				rowsBody.appendChild( tr );
			} );
	}

	function exportUrl( all ) {
		var params = all
			? { action: 'pwrg_export', _wpnonce: cfg.exportNonce, sort: state.sort, dir: state.dir }
			: Object.assign( requestParams(), { action: 'pwrg_export', _wpnonce: cfg.exportNonce, page: 1 } );

		delete params.nonce;

		return cfg.exportUrl + '?' + new URLSearchParams( params ).toString();
	}

	function bind() {
		rowsBody = $( 'pwrg-rows' );
		pagination = $( 'pwrg-pagination' );

		document.querySelectorAll( '.pwrg-sort' ).forEach( function ( th ) {
			th.addEventListener( 'click', function () {
				if ( state.sort === th.dataset.col ) {
					state.dir = state.dir === 'ASC' ? 'DESC' : 'ASC';
				} else {
					state.sort = th.dataset.col;
					state.dir = 'DESC';
				}
				load( 1 );
			} );
		} );

		[ [ 'pwrg-f-vpn', 'vpn' ], [ 'pwrg-f-tor', 'tor' ] ].forEach( function ( pair ) {
			var button = $( pair[ 0 ] );
			if ( ! button ) {
				return;
			}

			button.addEventListener( 'click', function () {
				state[ pair[ 1 ] ] = ! state[ pair[ 1 ] ];
				button.classList.toggle( 'button-primary', state[ pair[ 1 ] ] );
				load( 1 );
			} );
		} );

		$( 'pwrg-f-apply' ).addEventListener( 'click', function () {
			load( 1 );
		} );

		$( 'pwrg-f-reset' ).addEventListener( 'click', function () {
			[ 'pwrg-f-username', 'pwrg-f-user-id', 'pwrg-f-ip', 'pwrg-f-country' ].forEach( function ( id ) {
				$( id ).value = '';
			} );

			state.vpn = false;
			state.tor = false;
			$( 'pwrg-f-vpn' ).classList.remove( 'button-primary' );
			$( 'pwrg-f-tor' ).classList.remove( 'button-primary' );

			load( 1 );
		} );

		document.querySelectorAll( '.pwrg-filters input' ).forEach( function ( input ) {
			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					load( 1 );
				}
			} );
		} );

		$( 'pwrg-export' ).addEventListener( 'click', function () {
			window.location.href = exportUrl( false );
		} );

		$( 'pwrg-export-all' ).addEventListener( 'click', function () {
			window.location.href = exportUrl( true );
		} );

		$( 'pwrg-clear' ).addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.confirmClear ) ) {
				return;
			}

			var body = new URLSearchParams( { action: 'pwrg_clear', nonce: cfg.nonce } );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function () {
					load( 1 );
				} );
		} );

		load( 1 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', bind );
	} else {
		bind();
	}
} )();
