/**
 * @package Basic_Google_Maps_Placemarks
 * @link    http://wordpress.org/extend/plugins/basic-google-maps-placemarks/
 */

var BasicGoogleMapsPlacemarks = ( function( $ ) {
	var name, canvas, map, markerClusterer, options, markerData, markers, infoWindowContent, templateOptions;
	
	/**
	 * Constructor
	 */
	function init() {
		try {
			// Initialize variables
			name              = 'Basic Google Maps Placemarks';
			canvas            = document.getElementById( 'bgmp_map-canvas' );    // We have to use getElementById instead of a jQuery selector here in order to pass it to the Maps API.
			map               = undefined;
			markerClusterer   = undefined;
			markers           = {};
			infoWindowContent = {};
			templateOptions   = {
				evaluate:    /<#([\s\S]+?)#>/g,
				interpolate: /\{\{\{([\s\S]+?)\}\}\}/g,
				escape:      /\{\{([^\}]+?)\}\}(?!\})/g
			};

			if ( 'undefined' === typeof bgmpData ) {
				fatalUserError( 'bgmpData undefined.' );
				return;
			}

			options    = bgmpData.options;
			markerData = bgmpData.markers;
			bgmpData = {};	// It can't be deleted because Core declares it with `var`, so this is the next best thing.

			// Initialize single info window to reuse for each placemark
			infoWindow = new google.maps.InfoWindow( {
				content:     '',
				maxWidth:    options.infoWindowMaxWidth,
				pixelOffset: new google.maps.Size( options.infoWindowPixelOffset.width, options.infoWindowPixelOffset.height )
			} );

			// Format numbers
			options.zoom                = parseInt( options.zoom );
			options.latitude            = parseFloat( options.latitude );
			options.longitude           = parseFloat( options.longitude );
			options.clustering.maxZoom  = parseInt( options.clustering.maxZoom );
			options.clustering.gridSize = parseInt( options.clustering.gridSize );

			// Register event handlers
			$( '.' + 'bgmp_list' ).find( 'a' ).filter( '.' + 'bgmp_view-on-map' ).click( viewOnMap );

			// Build map
			if ( canvas ) {
				buildMap();
			} else {
				fatalUserError( "map canvas element doesn't exist." );
			}
		} catch ( exception ) {
			log( exception );
		}
	}

	/**
	 * Pull in the map options from WordPress' database and create the map
	 */
	function buildMap() {
		var mapOptions;

		if ( '' == options.mapWidth || '' == options.mapHeight || '' == options.latitude || '' == options.longitude || '' == options.zoom || '' == options.infoWindowMaxWidth ) {
			// @todo update w/ cluster options?
			// todo loop through array instead, b/c cleaner and can then notify which specific option wasn't set

			fatalUserError( 'map options not set.' );
			return;
		}

		mapOptions = {
			'zoom'                     : options.zoom,
			'center'                   : new google.maps.LatLng( options.latitude, options.longitude ),
			'mapTypeId'                : google.maps.MapTypeId[ options.type ],
			'mapTypeControl'           : 'off' != options.typeControl,
			'mapTypeControlOptions'    : { style: google.maps.MapTypeControlStyle[ options.typeControl ] },
			'navigationControl'        : 'off' != options.navigationControl,
			'navigationControlOptions' : { style: google.maps.NavigationControlStyle[ options.navigationControl ] },
			'streetViewControl'        : options.streetViewControl
		};

		// Override default width/heights from settings
		$( canvas ).css( 'width',  options.mapWidth );
		$( canvas ).css( 'height', options.mapHeight );
		// @todo this prevents users from using their own stylesheet?


		// Create the map
		try {
			map = new google.maps.Map( canvas, mapOptions );
		} catch( exception ) {
			fatalUserError( "couldn't build map." );
			log( exception );
		}
		addPlacemarks( map );    // @todo not supposed to add them when clustering is enabled? http://www.youtube.com/watch?v=Z2VF9uKbQjI


		// Activate marker clustering
		// todo modularize this
		if ( options.clustering.enabled ) {
			// BGMP stores markers in an object for direct access (e.g., markers[ 15 ] for ID 15), but MarkerCluster requires an array instead, so we convert them 
			var markersArray = [];
			for ( var m in markers ) {
				markersArray.push( markers[ m ] );
			}

			markerClusterer = new MarkerClusterer(
				map,
				markersArray,
				{
					maxZoom  : options.clustering.maxZoom,
					gridSize : options.clustering.gridSize,
					styles   : options.clustering.styles[ options.clustering.style ]
				}
			);
		}
	}

	/**
	 * Checks if the value is an integer
	 *
	 * @param {*} value
	 *
	 * @return {bool}
	 */
	function isInt( value ) {
		return ! isNaN( value ) && parseFloat( value ) == parseInt( value );

		// todo extend Number prototype instead of adding as part of this class
	}

	/**
	 * Pull the placemark posts from WordPress' database and add them to the map
	 *
	 * @param {object} map Google Maps map
	 */
	function addPlacemarks( map ) {
		// @todo - should probably refactor this since you pulled out the ajax. update phpdoc too

		if ( markerData.length > 0 ) {
			for ( var m in markerData ) {
				if ( markerData.hasOwnProperty( m ) ) {
					createMarker(
						map,
						markerData[ m ][ 'id' ],
						markerData[ m ][ 'title' ],
						markerData[ m ][ 'latitude' ],
						markerData[ m ][ 'longitude' ],
						markerData[ m ][ 'details' ],
						markerData[ m ][ 'icon' ],
						parseInt( markerData[ m ][ 'zIndex' ] )
					);
				}
			}
		}
	}

	/**
	 * Create a marker with an information window
	 *
	 * @param {object} map Google Maps map
	 * @param {int}    id ID of the marker post
	 * @param {string} title Placemark title
	 * @param {float}  latitude
	 * @param {float}  longitude
	 * @param {string} details Content of the info window
	 * @param {string} icon URL of the icon
	 * @param {int}    zIndex The desired position in the placemark stacking order
	 *
	 * @return {bool} True on success, false on failure
	 */
	function createMarker( map, id, title, latitude, longitude, details, icon, zIndex ) {
		var marker,
			infoWindowTemplate = _.template( $( '#tmpl-bgmp-info-window-content' ).html(), null, templateOptions );

		if ( isNaN( latitude ) || isNaN( longitude ) ) {
			log( title + " has invalid latitude and longitude." );

			return false;
		}

		if ( null == icon ) {
			// @todo - this check may not be needed anymore

			log( title + " icon wasn't passed in." );

			return false;
		}

		if ( ! isInt( zIndex ) ) {
			//log( prefix + "createMarker():  "+ title +" z-index wasn't valid." );	// this would fire any time it's empty

			zIndex = 0;
		}

		try {
			// Replace commas with periods. Some (human) languages use commas to delimit the fraction from the whole number, but Google Maps doesn't accept that.
			latitude  = parseFloat( latitude.replace(  ',', '.' ) );
			longitude = parseFloat( longitude.replace( ',', '.' ) );

			marker = new google.maps.Marker( {
				'bgmpID'   : id,
				'position' : new google.maps.LatLng( latitude, longitude ),
				'map'      : map,
				'icon'     : icon,
				'title'    : title,
				'zIndex'   : zIndex
			} );

			markers[ id ]           = marker;	// todo just have a single object to store all this, like wordcamp central theme does it. probably other lessons to learn from there too
			infoWindowContent[ id ] = infoWindowTemplate( {
				id:		   id,
				title:     title,
				details:   details,
				latitude:  latitude,
				longitude: longitude,
				icon:      icon
			} );

			google.maps.event.addListener( marker, 'click', function () {
				openInfoWindow( map, marker, infoWindowContent[ id ] );
			} );

			return true;
		} catch ( exception ) {
			fatalUserError( "Couldn't add map placemarks." );
			log( exception );
		}
	}

	/**
	 * Opens an info window on the map
	 *
	 * @param {object} map
	 * @param {object} marker
	 * @param {string} content
	 */
	function openInfoWindow( map, marker, content ) {
		infoWindow.setContent( content );
		infoWindow.open( map, marker );

		if ( options.viewOnMapScroll ) {
			$( 'html, body' ).animate(
				{ scrollTop: $( '#' + 'bgmp_map-canvas' ).offset().top },
				900
			);
		}
	}

	/**
	 * Focuses the [bgmp-map] on the marker that corresponds to the [bgmp-list] link that was clicked
	 *
	 * @param {object} event
	 */
	function viewOnMap( event ) {
		var id = $( this ).data( 'marker-id' );
		openInfoWindow( map, markers[ id ], infoWindowContent[ id ] );
	}

	/**
	 * Show a fatal error to the user
	 *
	 * @param {string} message
	 */
	function fatalUserError( message ) {
		// todo add class for making error message red, so that it stands out?

		$( canvas ).html( name + ' error: ' + message );
	}

	/**
	 * Log a message to the console
	 *
	 * @param {*} error
	 */
	function log( error ) {
		if ( ! window.console ) {
			return;
		}

		if ( 'string' == typeof error ) {
			console.log( 'Basic Google Maps Placemarks: ' + error );
		} else {
			console.log( 'Basic Google Maps Placemarks: ', error );
		}
	}

	/*
	 * Reveal public methods
	 */
	return {
		init: init
	};
} )( jQuery );

jQuery( document ).ready( BasicGoogleMapsPlacemarks.init( bgmpData ) );
