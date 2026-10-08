<?php

declare(strict_types=1);

namespace Ankus\Analysis;

use Ankus\Capability as C;

/**
 * The tables that map PHP built-ins to capabilities. Keys are lowercase
 * global function / class names.
 */
final class Sinks
{
    /** @var array<string, list<C>> */
    public const FUNCTIONS = [
        // Shell and process control
        'exec' => [C::Exec], 'shell_exec' => [C::Exec], 'system' => [C::Exec],
        'passthru' => [C::Exec], 'proc_open' => [C::Exec], 'popen' => [C::Exec],
        'pcntl_exec' => [C::Exec], 'pcntl_fork' => [C::Exec], 'posix_kill' => [C::Exec],
        'posix_setuid' => [C::Exec], 'posix_setgid' => [C::Exec], 'expect_popen' => [C::Exec],
        // Code evaluation that is a function call (eval itself is a language construct)
        'create_function' => [C::CodeEval],
        // Network
        'fsockopen' => [C::Network], 'pfsockopen' => [C::Network],
        'stream_socket_client' => [C::Network], 'stream_socket_server' => [C::Network],
        'socket_create' => [C::Network], 'socket_connect' => [C::Network], 'socket_create_pair' => [C::Network],
        'curl_init' => [C::Network], 'curl_exec' => [C::Network], 'curl_multi_exec' => [C::Network],
        'curl_multi_init' => [C::Network], 'ftp_connect' => [C::Network], 'ftp_ssl_connect' => [C::Network],
        'ldap_connect' => [C::Network], 'mail' => [C::Network], 'mb_send_mail' => [C::Network],
        'dns_get_record' => [C::Network], 'gethostbyname' => [C::Network], 'gethostbynamel' => [C::Network],
        'checkdnsrr' => [C::Network], 'get_headers' => [C::Network], 'ssh2_connect' => [C::Network],
        'imap_open' => [C::Network], 'snmpget' => [C::Network],
        // Files: write
        'file_put_contents' => [C::FileWrite], 'unlink' => [C::FileWrite], 'rename' => [C::FileWrite],
        'mkdir' => [C::FileWrite], 'rmdir' => [C::FileWrite], 'touch' => [C::FileWrite],
        'chmod' => [C::FileWrite], 'chown' => [C::FileWrite], 'chgrp' => [C::FileWrite],
        'lchown' => [C::FileWrite], 'lchgrp' => [C::FileWrite], 'symlink' => [C::FileWrite], 'link' => [C::FileWrite],
        'tempnam' => [C::FileWrite], 'tmpfile' => [C::FileWrite], 'move_uploaded_file' => [C::FileWrite],
        'copy' => [C::FileRead, C::FileWrite],
        // Files: read
        'file_get_contents' => [C::FileRead], 'file' => [C::FileRead], 'readfile' => [C::FileRead],
        'fopen' => [], // decided by mode argument
        'scandir' => [C::FileRead], 'glob' => [C::FileRead], 'opendir' => [C::FileRead],
        'parse_ini_file' => [C::FileRead], 'highlight_file' => [C::FileRead], 'show_source' => [C::FileRead],
        'simplexml_load_file' => [C::FileRead], 'md5_file' => [C::FileRead], 'sha1_file' => [C::FileRead],
        'hash_file' => [C::FileRead],
        // Environment
        'getenv' => [C::Env], 'putenv' => [C::Env], 'apache_getenv' => [C::Env], 'apache_setenv' => [C::Env],
        // Object injection surface
        'unserialize' => [C::Unserialize],
        // Native code
        'dl' => [C::Native],
    ];

    /** Functions whose first argument may be a URL, making them network capable. */
    public const URL_ARG_FUNCTIONS = [
        'file_get_contents' => 0, 'fopen' => 0, 'file' => 0, 'readfile' => 0, 'copy' => 0,
        'get_headers' => 0, 'simplexml_load_file' => 0, 'getimagesize' => 0, 'parse_ini_file' => 0,
        'file_put_contents' => 0, 'md5_file' => 0, 'sha1_file' => 0, 'hash_file' => 1,
    ];

    /** Functions that take a callable, and at which argument positions. */
    public const CALLABLE_ARGS = [
        'call_user_func' => [0], 'call_user_func_array' => [0], 'forward_static_call' => [0],
        'forward_static_call_array' => [0], 'array_map' => [0], 'array_filter' => [1], 'array_walk' => [1],
        'array_walk_recursive' => [1], 'array_reduce' => [1], 'usort' => [1], 'uasort' => [1], 'uksort' => [1],
        'array_udiff' => [-1], 'array_uintersect' => [-1], 'preg_replace_callback' => [1],
        'register_shutdown_function' => [0], 'register_tick_function' => [0], 'set_error_handler' => [0],
        'set_exception_handler' => [0], 'spl_autoload_register' => [0], 'ob_start' => [0],
        'header_register_callback' => [0], 'iterator_apply' => [1], 'mb_ereg_replace_callback' => [1],
    ];

    /** Classes whose construction grants a capability. */
    public const CLASSES = [
        'soapclient' => [C::Network],
        'splfileobject' => [], // decided by mode argument
        'ffi' => [C::Native],
    ];

    /** Static methods that grant a capability. */
    public const STATIC_METHODS = [
        'ffi::cdef' => [C::Native], 'ffi::load' => [C::Native], 'ffi::scope' => [C::Native],
    ];

    /** Pure string functions we evaluate when all their arguments are known. */
    public const FOLDABLE = [
        'strrev', 'str_rot13', 'base64_decode', 'hex2bin', 'bin2hex', 'strtolower', 'strtoupper',
        'ucfirst', 'lcfirst', 'ucwords', 'trim', 'ltrim', 'rtrim', 'chr', 'sprintf', 'str_replace',
        'str_ireplace', 'substr', 'strtr', 'str_repeat', 'urldecode', 'rawurldecode', 'gzinflate',
        'gzuncompress', 'gzdecode', 'convert_uudecode', 'pack', 'dirname', 'basename', 'str_pad',
        'strval', 'base_convert', 'implode', 'join',
    ];

    /** Folding through these means the string was deliberately disguised. */
    public const DECODERS = [
        'strrev', 'str_rot13', 'base64_decode', 'hex2bin', 'chr', 'gzinflate', 'gzuncompress',
        'gzdecode', 'convert_uudecode', 'pack', 'urldecode', 'rawurldecode', 'base_convert',
    ];

    /** Functions whose return value is external input. */
    public const SOURCES = [
        'file_get_contents', 'fread', 'fgets', 'fgetc', 'stream_get_contents', 'curl_exec',
        'curl_multi_getcontent', 'getenv', 'file', 'socket_read', 'socket_recv', 'fgetcsv',
        'stream_socket_recvfrom', 'apache_getenv', 'readline', 'gethostbyaddr', 'dns_get_record',
        'json_decode', 'unserialize',
    ];

    public const SUPERGLOBALS = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_ENV', '_FILES', 'GLOBALS'];

    /** Path fragments that point at credentials or keys. */
    public const SENSITIVE_PATHS = [
        '/.ssh/', 'id_rsa', 'id_ed25519', '.aws/credentials', '.aws/config', 'auth.json',
        '.npmrc', '.pypirc', '.git-credentials', '.netrc', '/etc/passwd', '/etc/shadow',
        '.kube/config', '.docker/config.json', '.config/gcloud', 'wallet.dat', 'Library/Keychains',
        '.gnupg/', '.config/gh/hosts.yml', '.composer/auth.json',
    ];

    /** Stream wrappers that make include() evaluate non-file code. */
    public const CODE_WRAPPERS = ['data:', 'php://input', 'php://filter', 'http://', 'https://', 'ftp://', 'expect://', 'phar://'];

    public const NETWORK_SCHEMES = ['http://', 'https://', 'ftp://', 'ftps://', 'ssl://', 'tls://', 'tcp://', 'udp://', 'ssh2.'];

    public const PHP_EXTENSIONS = ['php', 'inc', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'module'];

    public static function isFunctionSink(string $name): bool
    {
        return isset(self::FUNCTIONS[$name]) || isset(self::CALLABLE_ARGS[$name]);
    }
}
