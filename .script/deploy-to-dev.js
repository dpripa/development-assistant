const dotenv = require( 'dotenv' ).config();
const FtpDeploy = require( 'ftp-deploy' );
const path = require( 'path' );
const ftp = new FtpDeploy();
const log = require( 'log-beautify' );
const release = require( '../package.json' ).release;

ftp
	.deploy( {
		host: process.env.DEV_FTP_HOST,
		port: 21,
		user: process.env.DEV_FTP_NAME,
		password: process.env.DEV_FTP_PWD,
		localRoot: path.dirname(__dirname),
		remoteRoot: process.env.DEV_FTP_DIR,
		include: release.directories.map(
			( dir ) => dir + '/**'
		).concat(release.files),
		exclude: [ '**/.DS_Store', 'node_modules/**', 'src/**' ],
		deleteRemote: false,
		forcePasv: true,
		sftp: false,
	} )
	.then( ( res ) => log.success_( 'Deployment to dev is complete: ' + res ) )
	.catch( ( err ) => log.error_( err ) );
