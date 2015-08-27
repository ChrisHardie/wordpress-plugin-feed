<?php

use WordPressPluginFeed\Parsers\Parser;
use WordPressPluginFeed\Generators\Generator;

/**
 * Configuration tests
 */
class ConfigurationTest extends PHPUnit_Framework_TestCase
{
    /**
     * Limit configuration test
     */
    public function testLimitConfiguration()
    {
        putenv('OUTPUT_LIMIT=10');

        $parser = Parser::getInstance('jetpack');
        $releases = $parser->getReleases();

        $this->assertCount(10, $releases);
    }

    /**
     * Format configuration test
     */
    public function testFormatConfiguration()
    {
        putenv('OUTPUT_FORMAT=rss');

        $parser = Parser::getInstance('jetpack');
        $generator = Generator::getInstance();

        $output = $generator->generate($parser, null, false);

        $this->assertTrue((bool) preg_match('/<rss version="2.0"/', $output));
    }
}
