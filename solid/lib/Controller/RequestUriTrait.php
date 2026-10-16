<?php

namespace OCA\Solid\Controller;

/**
 * The public URI of the current request, used as the base for the RDF it
 * reads and writes.
 *
 * Scheme and host come from Nextcloud's IRequest, which honours
 * overwriteprotocol / overwritehost behind a reverse proxy. The raw
 * REQUEST_SCHEME / SERVER_NAME describe the proxy's hop instead (often
 * http:// and an internal name), and resources described with those IRIs
 * are different resources from the https:// ones clients use.
 */
trait RequestUriTrait
{
	/** Uses $this->request (OCP\IRequest) and $this->rawRequest (PSR-7 server request). */
	private function getRequestUri(): string
	{
		$scheme = $this->request->getServerProtocol();
		$host = $this->request->getServerHost();
		$path = $this->rawRequest->getServerParams()['REQUEST_URI'];

		return "{$scheme}://{$host}{$path}";
	}
}
