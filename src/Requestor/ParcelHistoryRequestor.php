<?php declare(strict_types = 1);

namespace Rix\CzechPost\Requestor;

use Rix\CzechPost\Client\ParcelHistoryClient;
use Rix\CzechPost\Entity\State;
use Rix\CzechPost\Enum\HistoryState;
use Rix\CzechPost\Exception\LogicalException;
use Rix\CzechPost\Exception\Runtime\ResponseException;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Psr\Http\Message\ResponseInterface;

final class ParcelHistoryRequestor extends AbstractRequestor
{

	/** @var ParcelHistoryClient */
	protected $client;

	public function __construct(ParcelHistoryClient $client)
	{
		$this->client = $client;
	}

	public function isDelivered(string $trackingNumber): bool
	{
		try {
			$currentState = $this->status($trackingNumber);

			return HistoryState::isDeliveredSuccessfully($currentState->getId());
		} catch (ResponseException $e) {
			return false;
		}
	}

	public function status(string $trackingNumber): State
	{
		$states = $this->history($trackingNumber);

		$last = end($states);
		if ($last === false) {
			throw new LogicalException('No parcel state');
		}

		return $last;
	}

	/**
	 * @return State[]
	 */
	public function history(string $trackingNumber): array
	{
		$resp = $this->client->history($trackingNumber);
		$rawStates = $this->parseStates($resp);

		$states = [];
		foreach ($rawStates as $rs) {
			$states[] = State::fromArray((array) $rs);
		}

		return $states;
	}

	/**
	 * @return mixed[]
	 */
	private function parseStates(ResponseInterface $response): array
	{
		if ($response->getStatusCode() !== 200) {
			throw new ResponseException(
				$response,
				sprintf('Server responded with status code "%d"', $response->getStatusCode())
			);
		}

		try {
			$data = Json::decode($response->getBody()->getContents());
		} catch (JsonException $e) {
			throw new ResponseException($response, 'Cannot decode response json');
		}

		if (!isset($data[0]) ||
			!isset($data[0]->states) ||
			!isset($data[0]->states->state) ||
			!is_array($data[0]->states->state) ||
			!array_key_exists(0, $data[0]->states->state)) {
			throw new ResponseException($response, 'Response does not contain any parcel state');
		}

		$first = $data[0]->states->state[0];
		$text = $first->text ?? '';
		if (!isset($first->id) || !HistoryState::isKnownState($first->id)) {
			throw new ResponseException(
				$response,
				sprintf('Unknown parcel state "%s". Description: "%s"', $first->id, $text)
			);
		}

		if (HistoryState::isErrorState($first->id)) {
			throw new ResponseException(
				$response,
				sprintf('Parcel tracking error. State: %s, Description: "%s"', $first->id, $text)
			);
		}

		return $data[0]->states->state;
	}

}
