<?php

namespace OCA\Solid\Controller;

use Laminas\Diactoros\ServerRequest;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The base URI that stored RDF is read and written against.
 *
 * Relative IRIs in a Turtle document are resolved against its base (RDF 1.1
 * Turtle §6.3, https://www.w3.org/TR/turtle/#sec-iri-references), and IRIs are
 * equal only when their strings are (RDF 1.1 Concepts §3.2,
 * https://www.w3.org/TR/rdf11-concepts/#section-IRIs). An ACL served with an
 * http:// base therefore names different resources from the https:// ones the
 * client asked for: its acl:accessTo and acl:default (WAC 1.0.0 §4.1,
 * https://solidproject.org/TR/2024/wac-20240512#access-objects) no longer match,
 * and a client building a resource ACL from it copies none of the owner's
 * Authorizations.
 *
 * Solid servers "SHOULD use TLS connections through the https URI scheme"
 * (Solid Protocol 0.11.0, §2.1, https://solidproject.org/TR/2024/protocol-20240512#server-tls-https),
 * which behind a TLS-terminating proxy is only visible through Nextcloud's
 * overwriteprotocol / overwritehost, not through REQUEST_SCHEME / SERVER_NAME.
 *
 * @coversDefaultClass \OCA\Solid\Controller\RequestUriTrait
 * @covers ::getRequestUri
 */
class RequestUriTraitTest extends TestCase
{
	private const PATH = '/apps/solid/~alice/storage/.acl';

	/** What PHP sees behind a proxy that terminates TLS. */
	private const BEHIND_PROXY = [
		'REQUEST_SCHEME' => 'http',
		'SERVER_NAME' => 'nextcloud-internal',
		'REQUEST_URI' => self::PATH,
	];

	private function uriFor(string $protocol, string $host): string
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getServerProtocol')->willReturn($protocol);
		$request->method('getServerHost')->willReturn($host);

		$subject = new class($request, new ServerRequest(self::BEHIND_PROXY)) {
			use RequestUriTrait;
			public $request;
			public $rawRequest;
			public function __construct($request, $rawRequest) {
				$this->request = $request;
				$this->rawRequest = $rawRequest;
			}
			public function uri(): string {
				return $this->getRequestUri();
			}
		};

		return $subject->uri();
	}

	/** @testdox The base URI uses Nextcloud's public scheme and host, not the proxy hop's */
	public function testUsesPublicSchemeAndHost()
	{
		$this->assertSame('https://cloud.example.org' . self::PATH, $this->uriFor('https', 'cloud.example.org'));
	}

	/** @testdox Relative IRIs in stored Turtle resolve to the https resource the client requested (Turtle §6.3) */
	public function testRelativeIrisResolveToPublicResource()
	{
		$graph = new \EasyRdf\Graph();
		$graph->parse(
			'@prefix acl: <http://www.w3.org/ns/auth/acl#> . <#owner> acl:accessTo <./> ; acl:default <./> .',
			'turtle',
			$this->uriFor('https', 'cloud.example.org')
		);

		$owner = $graph->resource('https://cloud.example.org/apps/solid/~alice/storage/.acl#owner');
		$this->assertSame(
			'https://cloud.example.org/apps/solid/~alice/storage/',
			$owner->get('<http://www.w3.org/ns/auth/acl#default>')->getUri()
		);
	}

	/**
	 * @testdox Every controller that serves RDF builds its base URI through the trait, and reads the host from Nextcloud
	 * @dataProvider provideRdfControllers
	 */
	public function testRdfControllersUseTrait(string $class)
	{
		$this->assertContains(RequestUriTrait::class, class_uses($class));
		$source = file_get_contents((new \ReflectionClass($class))->getFileName());
		// The proxy hop's view of the request, which overwriteprotocol/overwritehost do not change.
		$this->assertDoesNotMatchRegularExpression('/\\[[\'"](REQUEST_SCHEME|SERVER_NAME)[\'"]\\]/', $source);
	}

	public static function provideRdfControllers()
	{
		return [
			'calendar' => [CalendarController::class],
			'contacts' => [ContactsController::class],
			'profile' => [ProfileController::class],
			'storage' => [StorageController::class],
			'webhook' => [SolidWebhookController::class],
		];
	}
}
