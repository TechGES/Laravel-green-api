<?php

namespace Ges\LaravelGreenApi\Tests\Feature;

use Ges\LaravelGreenApi\Models\GreenApiConversation;
use Ges\LaravelGreenApi\Tests\Fixtures\User;
use Ges\LaravelGreenApi\Tests\TestCase;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

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

    public function test_postgres_existence_queries_cast_contact_owner_key_to_text(): void
    {
        $connection = $this->app['db']->connection();
        $originalGrammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new PostgresGrammar($connection));

        try {
            $conversationSql = GreenApiConversation::query()->whereHas('contact')->toSql();
            $contactSql = User::query()->whereHas('greenApiConversation')->toSql();
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }

        $this->assertStringContainsString(
            '"green_api_conversations"."contact_id" = cast("users"."id" as text)',
            $conversationSql
        );
        $this->assertStringContainsString(
            '"contact_id" = cast("users"."id" as text)',
            $contactSql
        );
    }
}
