<?php

namespace Ges\LaravelGreenApi\Tests\Feature;

use Ges\LaravelGreenApi\Models\GreenApiConversation;
use Ges\LaravelGreenApi\Tests\Fixtures\User;
use Ges\LaravelGreenApi\Tests\TestCase;

class GreenApiRelationTest extends TestCase
{
    public function test_contact_has_dynamic_one_to_one_conversation_relation(): void
    {
        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 78',
        ]);

        $conversation = GreenApiConversation::query()->create([
            'contact_id' => (string) $user->getKey(),
            'chat_id' => '33612345678@c.us',
            'phone' => '33612345678',
        ]);

        $resolved = $user->greenApiConversation;

        $this->assertInstanceOf(GreenApiConversation::class, $resolved);
        $this->assertTrue($conversation->is($resolved));
    }
}
