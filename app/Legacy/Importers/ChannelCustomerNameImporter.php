<?php

namespace App\Legacy\Importers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy `const CUSTOMER_NAME_TO_CHANNEL = { 'name': 'channel', … }` (code of the page, L26506) → channel_customer_names: the
 * customers whose name tells the channel of a realization without an order (ADR-0052). Idempotent; the order of the literal is
 * kept (legacy takes the first name that contains or is contained).
 */
class ChannelCustomerNameImporter
{
    /** @param string|null $html the legacy page (tests); by default the accepted reference */
    public function __construct(private readonly ?string $html = null) {}

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        if (! preg_match('/const CUSTOMER_NAME_TO_CHANNEL\s*=\s*\{(.*?)\};/s', $this->html ?? $this->acceptedReference(), $block)) {
            throw new RuntimeException('CUSTOMER_NAME_TO_CHANNEL not found in the legacy reference');
        }
        preg_match_all("/'([^']+)'\\s*:\\s*'(\\w+)'/u", $block[1], $pairs, PREG_SET_ORDER);
        if ($pairs === []) {
            throw new RuntimeException('CUSTOMER_NAME_TO_CHANNEL is empty');
        }

        return DB::transaction(function () use ($pairs, $snapshotId, $by) {
            $stats = ['names' => count($pairs), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($pairs as $i => [, $name, $channel]) {
                $row = DB::table('channel_customer_names')->where('name', $name)->first();
                if ($row === null) {
                    DB::table('channel_customer_names')->insert(['name' => $name, 'channel' => $channel, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
                    $stats['created']++;
                } elseif ($row->channel !== $channel || $row->sort_order !== $i) {
                    DB::table('channel_customer_names')->where('id', $row->id)->update(['channel' => $channel, 'sort_order' => $i, 'updated_at' => now()]);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'channel_customer_names', null, ['names' => count($pairs)], $by);

            return $stats;
        });
    }

    private function acceptedReference(): string
    {
        $config = json_decode((string) file_get_contents(base_path('tools/harness/config.json')), true, flags: JSON_THROW_ON_ERROR);
        $sha = $config['source']['accepted_ref']['sha256'];
        foreach (glob(base_path('.local-audit/blobs/*.html')) ?: [] as $file) {
            if (hash_file('sha256', $file) === $sha) {
                return (string) file_get_contents($file);
            }
        }
        throw new RuntimeException('The accepted legacy reference is not in .local-audit/blobs — run make audit-daily first');
    }
}
