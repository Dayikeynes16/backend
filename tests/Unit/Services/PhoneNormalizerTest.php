<?php

namespace Tests\Unit\Services;

use App\Services\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function phoneProvider(): array
    {
        return [
            'diez digitos' => ['9931234567', '+529931234567'],
            'con espacios' => ['993 123 4567', '+529931234567'],
            'con guiones' => ['993-123-4567', '+529931234567'],
            'con parentesis' => ['(993) 123 4567', '+529931234567'],
            'ya en e164' => ['+529931234567', '+529931234567'],
            'e164 con espacios' => ['+52 993 123 4567', '+529931234567'],
            'lada sin mas' => ['529931234567', '+529931234567'],
            'movil legacy 521' => ['5219931234567', '+529931234567'],
            'movil legacy con mas' => ['+52 1 993 123 4567', '+529931234567'],
            'null' => [null, null],
            'vacio' => ['', null],
            'solo simbolos' => ['---', null],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function test_normalize(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($input));
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function plausibleProvider(): array
    {
        return [
            'mexicano de diez digitos' => ['+529931234567', true],
            'internacional de once' => ['+46634532343', true],
            'internacional de ocho' => ['+45566666', true],
            'cuatro digitos' => ['+8556', false],
            'tres digitos' => ['+344', false],
            'un cero' => ['+0', false],
            'empieza en cero' => ['+0975445800765', false],
            'demasiado largo' => ['+1234567890123456', false],
            'null' => [null, false],
            'sin mas' => ['9931234567', false],
        ];
    }

    #[DataProvider('plausibleProvider')]
    public function test_is_plausible(?string $e164, bool $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::isPlausible($e164));
    }

    public function test_normalize_es_idempotente(): void
    {
        $once = PhoneNormalizer::normalize('993 123 4567');
        $this->assertSame($once, PhoneNormalizer::normalize($once));
    }

    public function test_display_local_formatea_para_humanos(): void
    {
        $this->assertSame('993 123 4567', PhoneNormalizer::displayLocal('+529931234567'));
    }

    public function test_display_local_tolera_null(): void
    {
        $this->assertSame('', PhoneNormalizer::displayLocal(null));
    }
}
