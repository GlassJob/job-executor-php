<?php
declare(strict_types=1);

namespace JobCenter\Tests\Http;

use JobCenter\Http\Signer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    /**
     * @return list<array{0:array<string,mixed>}>
     */
    public static function fixtureProvider(): array
    {
        $path = dirname(__DIR__) . '/Http/fixtures/admin-hmac.json';
        $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [$row];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $fx
     */
    #[DataProvider('fixtureProvider')]
    public function testFixturesMatchCenterCanonical(array $fx): void
    {
        self::assertSame($fx['canon_query'], Signer::canonicalQuery($fx['raw_query']), $fx['name']);
        self::assertSame($fx['body_hash'], Signer::bodyHash($fx['body']), $fx['name']);
        $canonical = Signer::canonical(
            $fx['method'],
            $fx['path'],
            $fx['raw_query'],
            $fx['client_id'],
            $fx['timestamp'],
            $fx['nonce'],
            $fx['operator_id'],
            $fx['operator_name'],
            $fx['body'],
        );
        self::assertSame($fx['canonical'], $canonical, $fx['name']);
        self::assertSame($fx['signature'], Signer::sign($fx['secret'], $canonical), $fx['name']);
        $headers = Signer::headers(
            $fx['method'],
            $fx['path'],
            $fx['raw_query'],
            $fx['client_id'],
            $fx['secret'],
            $fx['timestamp'],
            $fx['nonce'],
            $fx['operator_id'],
            $fx['operator_name'],
            $fx['body'],
        );
        self::assertSame($fx['signature'], $headers[Signer::HEADER_SIGNATURE], $fx['name']);
        self::assertSame($fx['client_id'], $headers[Signer::HEADER_CLIENT_ID]);
        self::assertSame($fx['timestamp'], $headers[Signer::HEADER_TIMESTAMP]);
        self::assertSame($fx['nonce'], $headers[Signer::HEADER_NONCE]);
        self::assertSame($fx['operator_id'], $headers[Signer::HEADER_OPERATOR_ID]);
        self::assertSame($fx['operator_name'], $headers[Signer::HEADER_OPERATOR_NAME]);
    }

    public function testRfc3986AndEmptyBody(): void
    {
        self::assertSame('%20', Signer::rfc3986(' '));
        self::assertSame('%2B', Signer::rfc3986('+'));
        self::assertSame('hello%20world', Signer::rfc3986('hello world'));
        self::assertSame('%E4%BB%BB%E5%8A%A1%20%E5%90%8D%E7%A7%B0', Signer::rfc3986('任务 名称'));
        self::assertSame('a%2Fb%3Fc%26d%3De', Signer::rfc3986('a/b?c&d=e'));
        self::assertSame('~', Signer::rfc3986('~'));
        self::assertSame('%2A', Signer::rfc3986('*'));
        self::assertSame('-_.', Signer::rfc3986('-_.'));
        self::assertSame('caf%C3%A9', Signer::rfc3986('café'));
        self::assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', Signer::bodyHash(''));
    }

    public function testEncodeQuerySortsAndSkipsNull(): void
    {
        $q = Signer::encodeQuery([
            'limit' => 20,
            'page' => 1,
            'keyword' => 'hello',
            'unused' => null,
        ]);
        self::assertSame('keyword=hello&limit=20&page=1', $q);
        self::assertSame(
            'a=0&a=1&b=2&z=9',
            Signer::encodeQuery(['b' => '2', 'a' => ['1', '0'], 'z' => 9]),
        );
        self::assertSame(
            'keyword=%E4%BB%BB%E5%8A%A1%20%E5%90%8D%E7%A7%B0',
            Signer::encodeQuery(['keyword' => '任务 名称']),
        );
        self::assertSame('enabled=false', Signer::encodeQuery(['enabled' => false]));
        self::assertSame('enabled=true', Signer::encodeQuery(['enabled' => true]));
        self::assertSame('', Signer::encodeQuery([]));
    }

    public function testSemicolonQueryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signer::canonicalQuery('a=1;b=2');
    }
}
