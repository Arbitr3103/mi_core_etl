<?php
/**
 * Simple test that doesn't require external dependencies
 */

use PHPUnit\Framework\TestCase;

class SimpleTest extends TestCase {
    
    public function testBasicPHPFunctionality() {
        $this->assertTrue(true);
        $this->assertEquals(2, 1 + 1);
        $this->assertIsString("hello");
    }
    
    public function testArrayOperations() {
        $array = [1, 2, 3];
        $this->assertCount(3, $array);
        $this->assertContains(2, $array);
    }
    
    public function testStringOperations() {
        $string = "Hello World";
        $this->assertStringContains("World", $string);
        $this->assertEquals(11, strlen($string));
    }
}