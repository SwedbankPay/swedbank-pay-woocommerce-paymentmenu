'use strict';

const { execSync } = require( 'child_process' );
const fs           = require( 'fs' );

let gulp       = require( 'gulp' ),
	rename     = require( 'gulp-rename' ),
	sass       = require( 'gulp-sass' )( require( 'node-sass' ) ),
	sourcemaps = require( 'gulp-sourcemaps' ),
	cssmin     = require( 'gulp-clean-css' ),
	uglify     = require( 'gulp-uglify-es' ).default,
	wpPot      = require( 'gulp-wp-pot' );

gulp.task(
	'css:build',
	function () {
		return gulp.src( './assets/css/*.scss' )
		.pipe( sourcemaps.init() )
		.pipe( sass().on( 'error', sass.logError ) )
		.pipe( gulp.dest( './assets/css' ) )
		.pipe( cssmin() )
		.pipe(
			rename(
				{
					suffix: '.min',
				}
			)
		)
		.pipe( sourcemaps.write( '.' ) )
		.pipe( gulp.dest( './assets/css' ) );
	}
);

gulp.task(
	'css:build:watch',
	function () {
		gulp.watch( './assets/css/*.scss', gulp.parallel( 'css:build' ) );
	}
);

gulp.task(
	'js:build',
	function () {
		return gulp.src( ['./assets/js/*.js', '!./assets/js/*.min.js'] )
		.pipe( sourcemaps.init() )
		.pipe( uglify() )
		.pipe(
			rename(
				function (path) {
					path.extname = '.min.js';
				}
			)
		)
		.pipe( sourcemaps.write( '.' ) )
		.pipe( gulp.dest( './assets/js' ) );
	}
);

gulp.task(
	'js:build:watch',
	function () {
		gulp.watch( './assets/js/*.js', gulp.parallel( 'js:build' ) );
	}
);

/*
 * Copy third-party assets from node_modules to assets/vendor.
 */
const ITI_SRC  = './node_modules/intl-tel-input/dist';
const ITI_DEST = './assets/vendor/intl-tel-input';

gulp.task(
	'vendor:iti:js',
	function () {
		// The ESM build, published under a .js extension: some servers do not
		// map .mjs to a JavaScript MIME type, which would block the import.
		return gulp.src( `${ITI_SRC}/js/intlTelInput.mjs` )
		.pipe( rename( { basename: 'intlTelInput', extname: '.js' } ) )
		.pipe( gulp.dest( `${ITI_DEST}/js` ) );
	}
);

gulp.task(
	'vendor:iti:js:min',
	function () {
		// Upstream ships no minified ESM build, so make one. 136 KB -> ~49 KB.
		return gulp.src( `${ITI_SRC}/js/intlTelInput.mjs` )
		.pipe( uglify( { module: true, ecma: 2020 } ) )
		.pipe( rename( { basename: 'intlTelInput', extname: '.min.js' } ) )
		.pipe( gulp.dest( `${ITI_DEST}/js` ) );
	}
);

gulp.task(
	'vendor:iti:utils',
	function () {
		// Already Closure-compiled upstream -- copy verbatim, do not re-minify.
		return gulp.src( `${ITI_SRC}/js/utils.js` )
		.pipe( gulp.dest( `${ITI_DEST}/js` ) );
	}
);

gulp.task(
	'vendor:iti:locale',
	function () {
		// One locale is fetched lazily at runtime. index.js re-exports all of
		// them, which would defeat that, and types.js is not a locale.
		return gulp.src(
			[
				`${ITI_SRC}/js/locale/**/*.js`,
				`!${ITI_SRC}/js/locale/index.js`,
				`!${ITI_SRC}/js/locale/types.js`,
			]
		).pipe( gulp.dest( `${ITI_DEST}/js/locale` ) );
	}
);

gulp.task(
	'vendor:iti:css',
	function () {
		return gulp.src( [`${ITI_SRC}/css/intlTelInput.css`, `${ITI_SRC}/css/intlTelInput.min.css`] )
		.pipe( gulp.dest( `${ITI_DEST}/css` ) );
	}
);

gulp.task(
	'vendor:iti:img',
	function () {
		// The stylesheet references only the webp sprites -- the pngs are unused.
		return gulp.src( [`${ITI_SRC}/img/flags.webp`, `${ITI_SRC}/img/flags@2x.webp`], { encoding: false } )
		.pipe( gulp.dest( `${ITI_DEST}/img` ) );
	}
);

gulp.task(
	'vendor:build',
	gulp.parallel(
		'vendor:iti:js',
		'vendor:iti:js:min',
		'vendor:iti:utils',
		'vendor:iti:locale',
		'vendor:iti:css',
		'vendor:iti:img'
	)
);

gulp.task(
	'i18n:pot',
	function () {
		return gulp.src( ['./*.php', './src/**/*.php', './includes/**/*.php'] )
		.pipe(
			wpPot(
				{
					domain: 'swedbank-pay-payment-menu',
					package: 'Swedbank Pay WooCommerce Payment Menu',
					bugReport: 'https://github.com/SwedbankPay/swedbank-pay-woocommerce-paymentmenu/issues',
					lastTranslator: 'Swedbank Pay',
					team: 'Swedbank Pay',
				}
			)
		)
		.pipe( gulp.dest( './languages/swedbank-pay-payment-menu.pot' ) );
	}
);

gulp.task(
	'i18n:po',
	function (done) {
		const pot   = './languages/swedbank-pay-payment-menu.pot';
		const files = fs.readdirSync( './languages' ).filter( f => f.endsWith( '.po' ) );

		files.forEach(
			function (file) {
				execSync( `msgmerge --update --backup=none ./languages/${file} ${pot}` );
			}
		);
		done();
	}
);

gulp.task(
	'i18n:mo',
	function (done) {
		const files = fs.readdirSync( './languages' ).filter( f => f.endsWith( '.po' ) );

		files.forEach(
			function (file) {
				const base = file.replace( '.po', '' );
				execSync( `msgfmt ./languages/${file} -o ./languages/${base}.mo` );
			}
		);
		done();
	}
);

gulp.task(
	'i18n:build',
	gulp.series( 'i18n:pot', 'i18n:po', 'i18n:mo' )
);

gulp.task(
	'default',
	gulp.series(
		'vendor:build',
		gulp.parallel( 'css:build', 'js:build' )
	)
);
