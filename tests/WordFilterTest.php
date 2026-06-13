<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Chat\WordFilter;

class WordFilterTest extends TestCase
{
    public function testIsPronounceable(): void
    {
        $this->assertTrue(WordFilter::isPronounceable('hello'));
        $this->assertTrue(WordFilter::isPronounceable('chatty'));
        $this->assertTrue(WordFilter::isPronounceable('jules'));
        $this->assertFalse(WordFilter::isPronounceable('xkcd'));
        $this->assertFalse(WordFilter::isPronounceable('aaaaa'));
        $this->assertFalse(WordFilter::isPronounceable(''));
    }

    public function testIsAllowedWord(): void
    {
        // Note: WordFilter loads from words.txt, so we test basic logic
        $this->assertTrue(WordFilter::isAllowedWord('i'));
        $this->assertTrue(WordFilter::isAllowedWord('a'));
        // 'hello' should be in most words.txt or handleable
        // We'll rely on the suffix logic check too
        $this->assertTrue(WordFilter::isAllowedWord('running')); // handles 'run'
    }
}
