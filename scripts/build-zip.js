/**
 * Build script: creates a distributable ZIP for the dashboard-wp-cli plugin.
 *
 * Output: <repo-parent>/dashboard-wp-cli-<version>.zip
 * The ZIP contains a single top-level directory "dashboard-wp-cli/" so it can
 * be installed directly via WordPress › Plugins › Add New › Upload Plugin.
 *
 * Included:
 *   dashboard-wp-cli/dashboard-wp-cli.php
 *   dashboard-wp-cli/assets/
 *   dashboard-wp-cli/README.md
 *
 * Excluded:
 *   .git/, node_modules/, exports/, wp-cli.phar,
 *   *.DS_Store, package.json, package-lock.json, scripts/
 */

'use strict';

const { execSync } = require( 'child_process' );
const path         = require( 'path' );
const fs           = require( 'fs' );

const pluginDir  = path.resolve( __dirname, '..' );
const parentDir  = path.resolve( pluginDir, '..' );
const pluginName = 'dashboard-wp-cli';
const version    = JSON.parse( fs.readFileSync( path.join( pluginDir, 'package.json' ), 'utf8' ) ).version;
const zipName    = `${ pluginName }-${ version }.zip`;
const zipPath    = path.join( parentDir, zipName );

// Remove previous build if exists.
if ( fs.existsSync( zipPath ) ) {
	fs.unlinkSync( zipPath );
}

const includes = [
	`${ pluginName }/dashboard-wp-cli.php`,
	`${ pluginName }/assets`,
	`${ pluginName }/README.md`,
].join( ' ' );

const excludes = [
	`"${ pluginName }/.git/*"`,
	`"${ pluginName }/node_modules/*"`,
	`"${ pluginName }/exports/*"`,
	`"${ pluginName }/scripts/*"`,
	`"${ pluginName }/wp-cli.phar"`,
	`"${ pluginName }/package.json"`,
	`"${ pluginName }/package-lock.json"`,
	`"*/.DS_Store"`,
].join( ' ' );

const cmd = `zip -r "${ zipPath }" ${ includes } --exclude ${ excludes }`;

console.log( `Building ${ zipName }...` );
execSync( cmd, { cwd: parentDir, stdio: 'inherit' } );
console.log( `\nDone: ${ zipPath }` );
