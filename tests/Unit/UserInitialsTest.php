<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserInitialsTest extends TestCase
{
    public function test_initials_take_first_letters_of_two_words(): void
    {
        $this->assertSame('ДД', (new User(['name' => 'Демо Директор']))->initials());
        $this->assertSame('ИП', (new User(['name' => '  иван  петров сидорович']))->initials());
        $this->assertSame('А', (new User(['name' => 'Админ']))->initials());
    }
}
