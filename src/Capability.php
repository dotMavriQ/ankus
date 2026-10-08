<?php

declare(strict_types=1);

namespace Ankus;

/**
 * What a piece of code is able to do. A package's capability set is the
 * union of every capability found in its files.
 */
enum Capability: string
{
    case Exec = 'EXEC';
    case CodeEval = 'CODE_EVAL';
    case DynamicInclude = 'DYNAMIC_INCLUDE';
    case Network = 'NETWORK';
    case FileWrite = 'FILE_WRITE';
    case FileRead = 'FILE_READ';
    case Env = 'ENV';
    case SensitivePath = 'SENSITIVE_PATH';
    case Unserialize = 'UNSERIALIZE';
    case Native = 'NATIVE';
    case Obfuscation = 'OBFUSCATION';
    case DynamicUnresolved = 'DYNAMIC_UNRESOLVED';
    case Unanalyzable = 'UNANALYZABLE';

    public function describe(): string
    {
        return match ($this) {
            self::Exec => 'runs shell commands or controls processes',
            self::CodeEval => 'evaluates code built at runtime',
            self::DynamicInclude => 'includes files whose path is decided at runtime',
            self::Network => 'opens network connections',
            self::FileWrite => 'writes, moves or deletes files',
            self::FileRead => 'reads files',
            self::Env => 'reads or changes environment variables',
            self::SensitivePath => 'references credential or key file locations',
            self::Unserialize => 'unserializes data (object injection surface)',
            self::Native => 'loads native code (FFI, dl)',
            self::Obfuscation => 'feeds encoded or constructed strings into a sink',
            self::DynamicUnresolved => 'calls something whose name could not be resolved',
            self::Unanalyzable => 'contains PHP that could not be parsed',
        };
    }

    /** Capabilities that warrant a closer look whenever they appear. */
    public function isHighRisk(): bool
    {
        return match ($this) {
            self::Exec, self::CodeEval, self::Native, self::Obfuscation,
            self::DynamicUnresolved, self::SensitivePath, self::Unanalyzable => true,
            default => false,
        };
    }
}
