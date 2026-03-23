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
		gulp.parallel( 'css:build', 'js:build' )
	)
);
