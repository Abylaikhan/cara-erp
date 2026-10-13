<?php

namespace Tests\Feature\Access;

use App\Models\Customer;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\ChannelScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ERP-023: the channel directory of legacy SALES_CHANNELS and the server-side scope of getChannelFilter/channelMatches. */
class ChannelScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_holds_the_eight_legacy_channels_in_order(): void
    {
        $this->assertSame(
            ['office', 'salon_armada', 'salon_nazarbayeva', 'dealer_astana', 'dealer_aktau', 'dealer_shymkent', 'dealer_atyrau', 'dealer_aktobe'],
            SalesChannel::query()->ordered()->pluck('code')->all(),
        );
        $this->assertSame(['direct' => 1, 'salon' => 2, 'dealer' => 5], SalesChannel::query()->ordered()->get()->countBy('channel_group')->all());
        $this->assertSame('Астана', SalesChannel::find('dealer_astana')->region);
    }

    public function test_every_role_channel_exists_and_unknown_channels_are_rejected(): void
    {
        $codes = SalesChannel::query()->pluck('code')->all();
        foreach (config('erp.roles') as $role => $template) {
            $this->assertTrue(($template['channel'] ?? null) === null || in_array($template['channel'], $codes, true), $role);
        }
        $this->expectException(QueryException::class);
        User::factory()->create(['channel' => 'salon_unknown']);
    }

    public function test_filter_follows_legacy_get_channel_filter(): void
    {
        $this->assertNull(ChannelScope::filterFor(User::factory()->role('director')->make()));
        $this->assertNull(ChannelScope::filterFor(User::factory()->role('accountant')->make()));
        $this->assertSame('salon_armada', ChannelScope::filterFor(User::factory()->role('salon_armada')->make()));
        $this->assertSame('dealer_aktau', ChannelScope::filterFor(User::factory()->role('dealer_aktau')->make()));
        $this->assertSame(ChannelScope::SALONS, ChannelScope::filterFor(User::factory()->role('salon_armada_mgr')->make()));
        $this->assertSame(['salon_armada', 'salon_nazarbayeva'], ChannelScope::channelsFor(User::factory()->role('salon_armada_mgr')->make()));
    }

    public function test_queries_return_only_the_channels_a_user_may_see(): void
    {
        foreach (['office', 'salon_armada', 'salon_nazarbayeva', 'dealer_astana'] as $channel) {
            (new Customer(['name' => "Покупатель {$channel}", 'type' => 'client']))->forceFill(['channel' => $channel])->save();
        }
        $visible = fn (string $role) => ChannelScope::apply(Customer::query(), User::factory()->role($role)->make())->orderBy('channel')->pluck('channel')->all();

        $this->assertSame(['dealer_astana', 'office', 'salon_armada', 'salon_nazarbayeva'], $visible('director'));
        $this->assertSame(['salon_nazarbayeva'], $visible('salon_nazarbayeva'));
        $this->assertSame(['salon_armada', 'salon_nazarbayeva'], $visible('salon_armada_mgr'));
        $this->assertSame(['dealer_astana'], $visible('dealer_astana'));
        $this->assertTrue(ChannelScope::allows(User::factory()->role('salon_armada_mgr')->make(), 'salon_armada'));
        $this->assertFalse(ChannelScope::allows(User::factory()->role('salon_armada')->make(), 'office'));
    }
}
