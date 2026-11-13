<?php
declare(strict_types=1);

namespace JobCenter;

final class PendingStore
{
    public function __construct(private string $dir)
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('cannot create pending dir ' . $this->dir);
        }
    }

    /** @param mixed $data */
    public function save(string $attemptId, string $type, $data): void
    {
        $rec = [
            'attempt_id' => $attemptId,
            'type' => $type,
            'data' => $data === [] ? new \stdClass() : $data,
        ];
        $b = Json::encode($rec);
        $path = $this->pathFor($attemptId);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $b) === false) {
            throw new \RuntimeException('write pending tmp failed');
        }
        if (!rename($tmp, $path)) {
            throw new \RuntimeException('rename pending failed');
        }
    }

    public function remove(string $attemptId): void
    {
        $path = $this->pathFor($attemptId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return list<array{attempt_id:string,type:string,data:mixed}>
     */
    public function list(): array
    {
        $out = [];
        $entries = @scandir($this->dir);
        if ($entries === false) {
            return [];
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || !str_ends_with($name, '.json')) {
                continue;
            }
            $raw = @file_get_contents($this->dir . DIRECTORY_SEPARATOR . $name);
            if ($raw === false) {
                continue;
            }
            $rec = json_decode($raw, true);
            if (!is_array($rec) || ($rec['attempt_id'] ?? '') === '' || ($rec['type'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'attempt_id' => (string) $rec['attempt_id'],
                'type' => (string) $rec['type'],
                'data' => $rec['data'] ?? new \stdClass(),
            ];
        }
        return $out;
    }

    private function pathFor(string $attemptId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $attemptId) ?? 'x';
        return $this->dir . DIRECTORY_SEPARATOR . $safe . '.json';
    }
}
