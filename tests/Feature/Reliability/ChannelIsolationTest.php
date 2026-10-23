<?php

namespace Tests\Feature\Reliability;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * ERP-072, AC-11 «Открыть чужой канал прямым URL/ID…: сервер запрещает доступ и не отправляет чужие данные в props».
 * Every channel's records carry the channel's marker in their texts. Every salon and dealer account opens every page of
 * its role and every tab loaded on demand, and the records of its own channels by their id: no answer may hold the marker
 * of another channel, and its own markers must be seen (otherwise the check would prove nothing). Changing another
 * channel's records by id is refused in the tests of each module (LeadTest, CustomerTest, CrmTaskTest, ProjectTest,
 * OrderJournalTest).
 */
class ChannelIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNELS = ['office', 'salon_armada', 'salon_nazarbayeva', 'dealer_astana', 'dealer_aktau'];

    private const ROLES = ['salon_armada', 'salon_nazarbayeva', 'salon_armada_mgr', 'dealer_astana', 'dealer_aktau'];

    /** Lists sent when a tab is opened: page → [component, props]. Kept complete by the test below. */
    private const LAZY = [
        'sales' => ['Sales', ['realizations', 'report', 'worksheet']],
        'finance' => ['Finance', ['transactions', 'purchases', 'pl']],
        'crm' => ['Crm', ['clientOrders', 'boardOrders', 'projects']],
        'money' => ['Money', ['advances', 'calendar', 'bankRecon', 'salonRows']],
        'customers' => ['Customers', ['reconcileOrders']],
        // the salon payroll lines carry no channel (legacy shows them to every account of the page): nothing of a channel is in them
        'salon_report' => ['SalonReport', ['payroll']],
        'mrp' => ['Mrp', ['work', 'worksheet']],
        'esf' => ['Esf', ['recon']],
    ];

    /** @var array<string, array{lead: int, file: int, order: string}> */
    private array $records = [];

    /** ASCII: the JSON of an Inertia answer escapes Cyrillic, a Cyrillic marker would never be found. */
    private static function marker(string $channel): string
    {
        return 'MARKER_'.strtoupper($channel);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $bank = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        $cash = [];
        foreach (['salon_armada' => 'c854b1e4-c0f7-436b-a274-5e8a59c4ef78', 'salon_nazarbayeva' => '701d7d6d-3dda-4403-ac3c-e4a3987836ea'] as $salon => $key) {
            $cash[$salon] = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо '.$salon, 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 1]);
            DB::table('legacy_id_map')->insert(['source_table' => 'money_accounts', 'source_key' => $key, 'target_table' => 'money_accounts', 'target_id' => $cash[$salon], 'snapshot_id' => '20260101T000000Z']);
        }
        foreach (self::CHANNELS as $channel) {
            $m = self::marker($channel);
            DB::table('customers')->insert(['name' => "Customer {$m}", 'type' => 'client', 'channel' => $channel, 'comment' => $m]);
            $lead = DB::table('leads')->insertGetId(['name' => "Lead {$m}", 'channel' => $channel, 'status' => 'new', 'budget' => 0, 'comment' => $m, 'manager' => $m]);
            DB::table('lead_communications')->insert(['lead_id' => $lead, 'type' => 'call', 'text' => "Call {$m}", 'happened_on' => '2026-10-01']);
            DB::table('crm_tasks')->insert(['title' => "Task {$m}", 'lead_id' => $lead, 'status' => 'open', 'priority' => 'normal', 'due_date' => '2026-10-20']);
            $num = "{$m}-1";
            $order = DB::table('orders')->insertGetId(['num' => $num, 'channel' => $channel, 'customer' => "Customer {$m}", 'end_client' => "Client {$m}", 'seller' => $m,
                'comment' => $m, 'status' => 'queue', 'payment_pct' => 10, 'order_date' => '2026-10-01', 'due_date' => '2026-10-20', 'created_at' => '2026-10-01 10:00:00+05']);
            $item = DB::table('order_items')->insertGetId(['order_id' => $order, 'line_no' => 1, 'name' => "Item {$m}", 'qty' => 1, 'price' => 100, 'status' => 'queue',
                'progress' => 0, 'attrs' => '{}', 'resp' => $m]);
            DB::table('production_progress')->insert(['order_item_id' => $item, 'stages' => json_encode([['stage' => "Stage {$m}"]]), 'materials' => '[]', 'completed' => false]);
            DB::table('realizations')->insert(['order_num' => $num, 'order_id' => $order, 'doc_date' => '2026-10-05', 'name' => "Item {$m}", 'customer' => "Customer {$m}", 'revenue' => 100]);
            $account = $cash[$channel] ?? $bank;
            $tx = DB::table('money_transactions')->insertGetId(['account_id' => $account, 'tx_date' => '2026-10-02', 'flow' => 'income', 'category' => 'order_payment',
                'amount' => 10, 'order_num' => $num, 'order_id' => $order, 'description' => "Payment {$m}", 'counterparty' => $m]);
            DB::table('order_payments')->insert(['transaction_id' => $tx, 'order_num' => $num, 'order_id' => $order, 'amount' => 10]);
            $project = DB::table('projects')->insertGetId(['num' => "ПР-{$m}", 'title' => "Project {$m}", 'channel' => $channel, 'status' => 'request',
                'customer_name' => $m, 'description' => $m, 'created_at' => '2026-10-01 10:00:00+05', 'updated_at' => '2026-10-01 10:00:00+05']);
            DB::table('project_comments')->insert(['project_id' => $project, 'side' => 'client', 'text' => "Comment {$m}", 'created_at' => '2026-10-01 10:00:00+05']);
            Storage::disk('local')->put("projects/{$project}/{$m}.txt", "File {$m}");
            $file = DB::table('project_files')->insertGetId(['project_id' => $project, 'side' => 'customer', 'file_name' => "{$m}.txt", 'storage_path' => "projects/{$project}/{$m}.txt",
                'mime' => 'text/plain', 'size' => strlen("File {$m}"), 'created_at' => '2026-10-01 10:00:00+05']);
            if (str_starts_with($channel, 'salon_')) {
                DB::table('salon_worksheet_rows')->insert(['uuid' => (string) Str::uuid(), 'salon' => substr($channel, 6), 'order_date' => '2026-10-01',
                    'order_num' => $num, 'manager' => $m, 'note' => $m]);
            }
            $this->records[$channel] = ['lead' => $lead, 'file' => $file, 'order' => $num];
        }
    }

    private function answer(User $user, string $url, ?string $only = null, ?string $component = null): string
    {
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
        if ($only !== null) {
            $headers += ['X-Inertia-Partial-Data' => $only, 'X-Inertia-Partial-Component' => $component];
        }
        $response = $this->actingAs($user)->get($url, $headers);
        $this->assertContains($response->getStatusCode(), [200, 403, 404], "{$url} {$only}");

        return $response->getStatusCode() === 200 ? (string) $response->getContent() : '';
    }

    public function test_salon_and_dealer_accounts_get_no_record_of_another_channel_on_any_page_or_tab(): void
    {
        foreach (self::ROLES as $role) {
            $user = User::factory()->role($role)->create();
            $own = ChannelScope::channelsFor($user);
            $this->assertNotNull($own, "{$role} is bound to channels");
            $answers = [];
            foreach (Erp::pagesFor($user) as $page) {
                $answers["/{$page}"] = $this->answer($user, "/{$page}");
                [$component, $props] = self::LAZY[$page] ?? [null, []];
                foreach ($props as $prop) {
                    $answers["/{$page} {$prop}"] = $this->answer($user, "/{$page}", $prop, $component);
                }
            }
            foreach (self::CHANNELS as $channel) {
                $where = array_keys(array_filter($answers, fn (string $body) => str_contains($body, self::marker($channel))));
                if (in_array($channel, $own, true)) {
                    $this->assertNotEmpty($where, "{$role} sees no record of its own channel {$channel}: the check would prove nothing");
                } else {
                    $this->assertSame([], $where, "{$role} got records of {$channel}");
                }
            }
        }
    }

    public function test_records_of_another_channel_are_not_given_by_their_id(): void
    {
        foreach (self::ROLES as $role) {
            $user = User::factory()->role($role)->create();
            $own = ChannelScope::channelsFor($user);
            foreach (self::CHANNELS as $channel) {
                $r = $this->records[$channel];
                $answers = [
                    $this->actingAs($user)->get("/crm/leads/{$r['lead']}/communications", ['Accept' => 'application/json']),
                    $this->actingAs($user)->get("/crm/projects/files/{$r['file']}"),
                    $this->actingAs($user)->get('/customers/reconcile/payments/'.rawurlencode($r['order']), ['Accept' => 'application/json']),
                ];
                foreach ($answers as $response) {
                    $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
                    if (! in_array($channel, $own, true)) {
                        $this->assertStringNotContainsString(self::marker($channel), $body, "{$role} got a record of {$channel} by its id");
                    }
                }
            }
            // The own file is given (the CRM of a dealer shows only its projects; the salons see theirs).
            if (Erp::canAccess($user, 'crm')) {
                $this->actingAs($user)->get("/crm/projects/files/{$this->records[$own[0]]['file']}")->assertOk();
            }
        }
    }

    public function test_every_tab_loaded_on_demand_is_checked(): void
    {
        $optional = [];
        foreach (glob(app_path('Http/Controllers/*.php')) as $file) {
            preg_match_all("/'(\\w+)' => Inertia::optional\\(/", (string) file_get_contents($file), $m);
            $optional = [...$optional, ...$m[1]];
        }
        $checked = array_merge(...array_map(fn (array $p) => $p[1], array_values(self::LAZY)));
        sort($optional);
        sort($checked);
        $this->assertSame($optional, $checked, 'a new tab loaded on demand must be added to LAZY');
    }
}
