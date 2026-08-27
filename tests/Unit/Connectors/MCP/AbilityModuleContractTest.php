<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleContract;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the shared module metadata boundary used by MCP registries.
 */
final class AbilityModuleContractTest extends TestCase {

	public function test_first_party_and_intelligence_registries_satisfy_the_shared_contract(): void {
		$abilities   = ( new AbilitiesRegistry() )->modules();
		$intelligence = ( new IntelligenceRegistry() )->modules();

		AbilityModuleContract::validate( $abilities );
		AbilityModuleContract::validate( $intelligence );

		self::assertNotEmpty( $abilities );
		self::assertNotEmpty( $intelligence );
	}

	public function test_contract_rejects_an_open_input_schema(): void {
		$module = new class() implements AbilityModuleInterface {
			public function id(): string { return 'test.open'; }
			public function title(): string { return 'Open'; }
			public function description(): string { return 'Open schema.'; }
			public function group(): string { return 'Test'; }
			public function required_scopes(): array { return array( 'content:read' ); }
			public function is_read_only(): bool { return true; }
			public function input_schema(): array { return array( 'type' => 'object', 'properties' => array() ); }
			public function execute( array $args ): array { return $args; }
		};

		$this->expectException( LogicException::class );
		AbilityModuleContract::validate( array( 'test.open' => $module ) );
	}
}
