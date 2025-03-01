<?php

namespace Tests\Unit\Utilities;

use App\Utilities\Path;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function test_resolve_empty_path()
    {
        $this->assertEquals('', Path::resolve(''));
    }

    public function test_resolve_root_path()
    {
        $this->assertEquals('', Path::resolve('/'));
    }

    public function test_resolve_simple_path()
    {
        $this->assertEquals('foo/bar', Path::resolve('foo/bar'));
    }

    public function test_resolve_path_with_up()
    {
        $this->assertEquals('bar', Path::resolve('foo/../bar'));
    }

    public function test_resolve_path_with_cwd()
    {
        $this->assertEquals('foo/bar', Path::resolve('foo/./bar'));
    }

    public function test_resolve_path_with_up_escape()
    {
        $this->assertEquals('bar', Path::resolve('foo/../../bar'));
    }

    public function test_resolve_path_with_extra_slashes()
    {
        $this->assertEquals('foo/bar', Path::resolve('/foo/////bar/'));
    }
}
