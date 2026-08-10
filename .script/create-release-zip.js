const fs = require( 'fs' );
const path = require( 'path' );
const archiver = require( 'archiver' );
const log = require( 'log-beautify' );
const release = require( '../package.json' ).release;
const name = path.basename( path.dirname( __dirname ) );
const destination = './release';

if ( ! fs.existsSync( destination ) ) {
	fs.mkdirSync( destination, { recursive: true } );
}

const output = fs.createWriteStream( destination + '/' + name + '.zip' );
const archive = archiver( 'zip', {} );

output.on( 'close', function() {
	console.log( '\n' );
	log.success_( '"' + name + '.zip" saved to the "' + destination + '" folder.' );
	console.log( '\n' )
});

archive.on( 'error', function( err ) {
	throw err;
});

archive.pipe( output );

let directories = release.directories;
for ( let i = 0; i < directories.length; i++ ) {
	archive.directory( directories[i], name + '/' + directories[i], null );
}

let files = release.files;
for ( let i = 0; i < files.length; i++ ) {
	archive.file( files[i], { name: name + '/' + files[i] });
}

archive.finalize();
