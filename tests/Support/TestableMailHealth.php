<?php
namespace WPDevAssist\Tests\Support;

// These are private process pipes, not WordPress filesystem writes.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

use WPDevAssist\MailHealth\Store;

/** Deterministic clock and an isolated local SMTP server (no external delivery). */
class TestableMailHealth extends Store {
	public int $clock = 1900000000;

	public function now(): int {
		return $this->clock;
	}

	public static function smtp( string $mode = 'success' ): array {
		$script  = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) { exit(2); }
echo stream_socket_get_name($server, false), "\n";
flush();
$connection = stream_socket_accept($server, 10);
if (!$connection) { exit(3); }
stream_set_timeout($connection, 10);
$mode = $argv[1];
fwrite($connection, "220 fixture ESMTP\r\n");
$body = '';
while (($line = fgets($connection)) !== false) {
    $command = strtoupper(substr($line, 0, 4));
    if ($command === 'EHLO' || $command === 'HELO') {
        fwrite($connection, "250-fixture\r\n250 AUTH LOGIN\r\n");
    } elseif ($command === 'AUTH') {
        fwrite($connection, "535 Authentication failed\r\n");
    } elseif ($command === 'MAIL') {
        fwrite($connection, $mode === 'sender' ? "550 Sender rejected\r\n" : "250 OK\r\n");
    } elseif ($command === 'RCPT') {
        fwrite($connection, $mode === 'recipient' ? "550 Recipient rejected\r\n" : "250 OK\r\n");
    } elseif ($command === 'STAR') {
        fwrite($connection, "454 TLS unavailable\r\n");
    } elseif ($command === 'DATA') {
        fwrite($connection, "354 End with dot\r\n");
        while (($part = fgets($connection)) !== false && trim($part) !== '.') { $body .= $part; }
        fwrite($connection, $mode === 'quota' ? "552 Provider quota exceeded\r\n" : "250 Accepted\r\n");
    } elseif ($command === 'QUIT') {
        fwrite($connection, "221 Bye\r\n");
        break;
    } else {
        fwrite($connection, "250 OK\r\n");
    }
}
fclose($connection);
fclose($server);
echo base64_encode($body), "\n";
PHP;
		$pipes   = array();
		$process = proc_open( array( PHP_BINARY, '-r', $script, $mode ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start SMTP fixture.' );
		}
		fclose( $pipes[0] );
		$address = trim( fgets( $pipes[1] ) );
		return array( $process, $pipes, (int) substr( strrchr( $address, ':' ), 1 ) );
	}

	public static function finish_smtp( array $fixture ): string {
		$body = base64_decode( trim( stream_get_contents( $fixture[1][1] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		fclose( $fixture[1][1] );
		fclose( $fixture[1][2] );
		proc_close( $fixture[0] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_close
		return $body;
	}
}
