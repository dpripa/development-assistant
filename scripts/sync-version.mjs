#!/usr/bin/env node

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const versionFile = resolve( projectRoot, '.version' );
const envFile = resolve( projectRoot, '.env' );
const synchronizedFiles = [
	'development-assistant.php',
	'readme.txt',
	'package.json',
	'package-lock.json',
	'composer.json',
	'composer.lock',
];

function fail( message ) {
	throw new Error( message );
}

function run( command, args, options = {} ) {
	const result = spawnSync( command, args, {
		cwd: projectRoot,
		encoding: 'utf8',
		...options,
	} );

	if ( result.error ) {
		fail( `${ command } is required: ${ result.error.message }` );
	}

	if ( result.status !== 0 ) {
		const details = ( result.stderr || result.stdout || '' ).trim();
		fail( details || `${ command } exited with status ${ result.status }.` );
	}

	return result.stdout || '';
}

function readVersion() {
	if ( ! existsSync( versionFile ) ) {
		fail( '.version is missing.' );
	}

	const rawVersion = readFileSync( versionFile, 'utf8' );
	const version = rawVersion.trim();

	if ( rawVersion !== `${ version }\n` ) {
		fail( '.version must contain exactly one version followed by a newline.' );
	}

	if ( ! /^[0-9][0-9A-Za-z.-]*$/.test( version ) || version.includes( '..' ) || version.endsWith( '.' ) ) {
		fail( `Invalid version in .version: ${ version }` );
	}

	return version;
}

function assertTargetFilesClean() {
	for ( const file of synchronizedFiles ) {
		const workingTree = spawnSync( 'git', [ 'diff', '--quiet', '--', file ], { cwd: projectRoot } );
		const index = spawnSync( 'git', [ 'diff', '--cached', '--quiet', '--', file ], { cwd: projectRoot } );

		if ( workingTree.error || index.error ) {
			fail( 'git is required to synchronize the release version.' );
		}

		if ( workingTree.status !== 0 || index.status !== 0 ) {
			fail( `${ file } has uncommitted changes. Commit or restore it before synchronizing the version.` );
		}
	}
}

function assertVersionWasNotReleased( version, readme ) {
	const gitTag = run( 'git', [ 'tag', '--list', version ] ).trim();

	if ( gitTag ) {
		fail( `Version ${ version } already exists as a Git tag.` );
	}

	if ( existsSync( resolve( projectRoot, 'release/wporg/tags', version ) ) ) {
		fail( `Version ${ version } already exists in the local WordPress.org tags working copy.` );
	}

	if ( existsSync( envFile ) && typeof process.loadEnvFile === 'function' ) {
		process.loadEnvFile( envFile );
	}

	const pluginSlug = process.env.WPORG_PLUGIN_SLUG || 'development-assistant';
	const svnUrl = process.env.WPORG_SVN_URL || `https://plugins.svn.wordpress.org/${ pluginSlug }`;
	const remoteTags = run( 'svn', [ 'list', `${ svnUrl.replace( /\/$/, '' ) }/tags` ] )
		.split( /\r?\n/ )
		.filter( Boolean );

	if ( remoteTags.includes( `${ version }/` ) ) {
		fail( `Version ${ version } already exists in WordPress.org SVN tags.` );
	}

	const stableTag = readme.match( /^Stable tag:\s*(\S+)\s*$/m );

	if ( ! stableTag ) {
		fail( 'Could not find Stable tag in readme.txt.' );
	}

	const changelogHeading = new RegExp( `^= ${ version.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) } =$`, 'm' );

	if ( stableTag[ 1 ] !== version && changelogHeading.test( readme ) ) {
		fail( `Version ${ version } already exists in the readme.txt changelog.` );
	}
}

function replaceExactlyOnce( content, pattern, replacement, label ) {
	const matches = content.match( pattern );

	if ( ! matches || matches.length !== 1 ) {
		fail( `Expected exactly one ${ label } in its source file.` );
	}

	return content.replace( pattern, replacement );
}

function updateJsonVersion( path, version, updateRootPackage = false ) {
	const document = JSON.parse( readFileSync( path, 'utf8' ) );
	document.version = version;

	if ( updateRootPackage ) {
		if ( ! document.packages || ! document.packages[ '' ] ) {
			fail( 'package-lock.json does not contain the root package metadata.' );
		}

		document.packages[ '' ].version = version;
	}

	return `${ JSON.stringify( document, null, 2 ) }\n`;
}

function assertSynchronizedVersion( version ) {
	const plugin = readFileSync( resolve( projectRoot, 'development-assistant.php' ), 'utf8' );
	const readme = readFileSync( resolve( projectRoot, 'readme.txt' ), 'utf8' );
	const packageDocument = JSON.parse( readFileSync( resolve( projectRoot, 'package.json' ), 'utf8' ) );
	const packageLock = JSON.parse( readFileSync( resolve( projectRoot, 'package-lock.json' ), 'utf8' ) );
	const composerDocument = JSON.parse( readFileSync( resolve( projectRoot, 'composer.json' ), 'utf8' ) );
	const pluginVersion = plugin.match( /^ \* Version:[ \t]*(\S+)[ \t]*$/m );
	const stableTag = readme.match( /^Stable tag:[ \t]*(\S+)[ \t]*$/m );
	const expectedValues = new Map( [
		[ 'plugin header Version', pluginVersion?.[ 1 ] ],
		[ 'readme.txt Stable tag', stableTag?.[ 1 ] ],
		[ 'package.json version', packageDocument.version ],
		[ 'package-lock.json version', packageLock.version ],
		[ 'package-lock.json root package version', packageLock.packages?.[ '' ]?.version ],
		[ 'composer.json version', composerDocument.version ],
	] );

	for ( const [ label, actual ] of expectedValues ) {
		if ( actual !== version ) {
			fail( `${ label } is ${ actual || 'missing' }; expected ${ version } from .version.` );
		}
	}

	const escapedVersion = version.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const changelogHeading = new RegExp( `^= ${ escapedVersion } =$`, 'm' );
	const changelogMatch = changelogHeading.exec( readme );

	if ( ! changelogMatch ) {
		fail( `readme.txt does not contain a changelog entry for ${ version }.` );
	}

	const changelogRemainder = readme.slice( changelogMatch.index + changelogMatch[ 0 ].length );
	const nextHeadingIndex = changelogRemainder.search( /^= .* =$/m );
	const changelogEntry = nextHeadingIndex === -1
		? changelogRemainder
		: changelogRemainder.slice( 0, nextHeadingIndex );

	if ( changelogEntry.includes( 'TODO:' ) ) {
		fail( `readme.txt changelog for ${ version } still contains the generated TODO placeholder.` );
	}
}

function buildSynchronizedContents( version ) {
	const pluginPath = resolve( projectRoot, 'development-assistant.php' );
	const readmePath = resolve( projectRoot, 'readme.txt' );
	const packagePath = resolve( projectRoot, 'package.json' );
	const packageLockPath = resolve( projectRoot, 'package-lock.json' );
	const composerPath = resolve( projectRoot, 'composer.json' );

	const plugin = replaceExactlyOnce(
		readFileSync( pluginPath, 'utf8' ),
		/^ \* Version:[ \t]*\S+[ \t]*$/gm,
		` * Version: ${ version }`,
		'plugin header Version'
	);

	let readme = replaceExactlyOnce(
		readFileSync( readmePath, 'utf8' ),
		/^Stable tag:[ \t]*\S+[ \t]*$/gm,
		`Stable tag: ${ version }`,
		'readme.txt Stable tag'
	);

	const escapedVersion = version.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const changelogHeading = new RegExp( `^= ${ escapedVersion } =$`, 'm' );

	if ( ! changelogHeading.test( readme ) ) {
		readme = replaceExactlyOnce(
			readme,
			/^== Changelog ==[ \t]*$/gm,
			`== Changelog ==\n\n= ${ version } =\n- TODO: Describe the changes in this release.`,
			'readme.txt Changelog heading'
		);
	}

	return new Map( [
		[ pluginPath, plugin ],
		[ readmePath, readme ],
		[ packagePath, updateJsonVersion( packagePath, version ) ],
		[ packageLockPath, updateJsonVersion( packageLockPath, version, true ) ],
		[ composerPath, updateJsonVersion( composerPath, version ) ],
	] );
}

function synchronize() {
	const version = readVersion();
	assertTargetFilesClean();

	const readme = readFileSync( resolve( projectRoot, 'readme.txt' ), 'utf8' );
	assertVersionWasNotReleased( version, readme );

	const updatedContents = buildSynchronizedContents( version );
	const originalContents = new Map(
		Array.from( updatedContents.keys(), ( path ) => [ path, readFileSync( path, 'utf8' ) ] )
	);
	const composerLockPath = resolve( projectRoot, 'composer.lock' );
	const originalComposerLock = readFileSync( composerLockPath, 'utf8' );

	try {
		for ( const [ path, content ] of updatedContents ) {
			writeFileSync( path, content );
		}

		run( './scripts/run-composer.sh', [ 'update', '--lock' ], { stdio: 'inherit' } );
	} catch ( error ) {
		for ( const [ path, content ] of originalContents ) {
			writeFileSync( path, content );
		}

		writeFileSync( composerLockPath, originalComposerLock );
		throw error;
	}

	console.log( `Synchronized release version ${ version }.` );
	console.log( 'Complete the new readme.txt changelog entry before wporg-prepare.' );
}

try {
	const args = process.argv.slice( 2 );

	if ( args.length === 1 && args[ 0 ] === '--check' ) {
		const version = readVersion();
		assertSynchronizedVersion( version );
		console.log( `Release version ${ version } is synchronized.` );
	} else if ( args.length === 0 ) {
		synchronize();
	} else {
		fail( 'Usage: scripts/sync-version.mjs [--check]' );
	}
} catch ( error ) {
	console.error( `Error: ${ error.message }` );
	process.exitCode = 1;
}
