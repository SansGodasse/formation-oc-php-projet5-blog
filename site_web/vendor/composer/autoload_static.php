<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit7553223b4e2d66069d096a20a1b6b356
{
    public static $prefixLengthsPsr4 = array (
        'A' => 
        array (
            'App\\' => 4,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'App\\' => 
        array (
            0 => __DIR__ . '/../..' . '/app',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit7553223b4e2d66069d096a20a1b6b356::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit7553223b4e2d66069d096a20a1b6b356::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
