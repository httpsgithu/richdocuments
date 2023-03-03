<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2023, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Richdocuments\Controller;

use OC\Files\View;
use OCA\Richdocuments\Db\Wopi;
use OCP\AppFramework\Controller;
use OCP\Files\IRootFolder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IL10N;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ILogger;

use OCA\Richdocuments\AppConfig;
use OCA\Richdocuments\Db;
use OCA\Richdocuments\Helper;
use OCA\Richdocuments\FileService;
use OCA\Richdocuments\Http\DownloadResponse;
use OCP\IUserManager;
use OCP\IURLGenerator;

class WopiController extends Controller {
	/**
	 * @var IConfig
	 */
	private $settings;

	/**
	 * @var AppConfig
	 */
	private $appConfig;

	/**
	 * @var IL10N
	 */
	private $l10n;

	/**
	 * @var ILogger
	 */
	private $logger;
	
	/**
	 * @var FileService
	 */
	private $fileService;

	/**
	 * @var IRootFolder
	 */
	private $rootFolder;

	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;

	/**
	 * @var IUserManager
	 */
	private $userManager;

	// Signifies LOOL that document has been changed externally in this storage
	public const LOOL_STATUS_DOC_CHANGED = 1010;

	public function __construct(
		$appName,
		IRequest $request,
		IConfig $settings,
		AppConfig $appConfig,
		IL10N $l10n,
		ILogger $logger,
		FileService $fileService,
		IRootFolder $rootFolder,
		IURLGenerator $urlGenerator,
		IUserManager $userManager
	) {
		parent::__construct($appName, $request);
		$this->l10n = $l10n;
		$this->settings = $settings;
		$this->appConfig = $appConfig;
		$this->logger = $logger;
		$this->fileService = $fileService;
		$this->rootFolder = $rootFolder;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * 
	 * The Files endpoint operation CheckFileInfo. 
	 * 
	 * The operation returns information about a file, a user's permissions on that file, 
	 * and general information about the capabilities that the WOPI host has on the file.
	 */
	public function wopiCheckFileInfo(string $documentId): JSONResponse {
		$token = $this->request->getParam('access_token');

		list($fileId, , $version, $sessionId) = Helper::parseDocumentId($documentId);
		$this->logger->info('wopiCheckFileInfo(): Getting info about file {fileId}, version {version} by token {token}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version,
			'token' => $token ]);

		$row = new Db\Wopi();
		$row->loadBy('token', $token);

		$res = $row->getWopiForToken($token);
		if ($res == false) {
			$this->logger->debug('wopiCheckFileInfo(): getWopiForToken() failed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		// make sure file can be read when checking file info
		$file = $this->fileService->getFileHandle($fileId, $res['owner'], $res['editor']);
		if (!$file) {
			$this->logger->error('wopiCheckFileInfo(): Could not retrieve file', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		// trigger read operation while checking file info for user
		// after acquiring the token
		try {
			$file->fopen('rb');
		} catch (NotPermittedException $e) {
			$this->logger->error('wopiCheckFileInfo(): Could not open file - {error}', ['app' => $this->appName, 'error' => $e->getMessage()]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		} catch (\Exception $e) {
			$this->logger->error('wopiCheckFileInfo(): Unexpected Exception - {error}', ['app' => $this->appName, 'error' => $e->getMessage()]);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($res['editor'] && $res['editor'] != '') {
			$editor = $this->userManager->get($res['editor']);
			$editorId = $editor->getUID();
			$editorDisplayName = $editor->getDisplayName();
			$editorEmail = $editor->getEMailAddress();
			$userCanNotWriteRelative = !$file->getParent()->isCreatable();
		} else {
			$editorId = $this->l10n->t('remote user');
			$editorDisplayName = $this->l10n->t('remote user');
			$editorEmail = null;
			$userCanNotWriteRelative = true;
		}

		$canWrite = $res['attributes'] & WOPI::ATTR_CAN_UPDATE;
		$canPrint = $res['attributes'] & WOPI::ATTR_CAN_PRINT;
		$canExport = $res['attributes'] & WOPI::ATTR_CAN_EXPORT;

		if ($res['attributes'] & WOPI::ATTR_HAS_WATERMARK) {
			$watermark = \str_replace(
				'{viewer-email}',
				$editorEmail === null ? $editorDisplayName : $editorEmail,
				$this->appConfig->getAppValue('watermark_text')
			);
		} else {
			$watermark = null;
		}

		$result = [
			'BaseFileName' => $file->getName(),
			'Size' => $file->getSize(),
			'Version' => $version,
			'OwnerId' => $res['owner'],
			'UserId' => $editorId,
			'UserFriendlyName' => $editorDisplayName,
			'UserCanWrite' => $canWrite,
			'SupportsGetLock' => false,
			'SupportsLocks' => false, // TODO: implement functions below
			'UserCanNotWriteRelative' => $userCanNotWriteRelative,
			'PostMessageOrigin' => $res['server_host'],
			'LastModifiedTime' => Helper::toISO8601($file->getMTime()),
			'DisablePrint' => !$canPrint,
			'HidePrintOption' => !$canPrint,
			'DisableExport' => !$canExport,
			'HideExportOption' => !$canExport,
			'HideSaveOption' => !$canExport, // dont show the §save to OC§ option as user cannot download file
			'DisableCopy' => !$canExport, // disallow copying in document
			'WatermarkText' => $watermark,
		];
		
		$this->logger->debug("wopiCheckFileInfo(): Result: {result}", ['app' => $this->appName, 'result' => $result]);

		return new JSONResponse($result, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * 
	 * The Files endpoint file-level operations.
	 */
	public function wopiFileOperation(string $documentId): JSONResponse {
		$operation = $this->request->getHeader('X-WOPI-Override');
		switch ($operation) {
			case 'PUT_RELATIVE':
				return $this->wopiPutFileRelative($documentId);
			case 'LOCK':
			case 'UNLOCK':
			case 'REFRESH_LOCK':
			case 'GET_LOCK':
			case 'DELETE':
			case 'RENAME_FILE':
			case 'PUT_USER_INFO':
			case 'GET_SHARE_URL':
				$this->logger->warning("wopiFileOperation $operation unsupported", ['app' => $this->appName]);
				break;
			default:
				$this->logger->warning("wopiFileOperation $operation unknown", ['app' => $this->appName]);
		}

		return new JSONResponse([], Http::STATUS_NOT_IMPLEMENTED);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * 
	 * The File contents endpoint provides access to retrieve the contents of a file.
	 * 
	 * The GetFile operation retrieves a file from a host.
	 */
	public function wopiGetFile(string $documentId): Response {
		$token = $this->request->getParam('access_token');

		list($fileId, , $version, ) = Helper::parseDocumentId($documentId);
		$this->logger->info('wopiGetFile(): File {fileId}, version {version}, token {token}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version,
			'token' => $token ]);

		$row = new Db\Wopi();
		$row->loadBy('token', $token);

		//TODO: Support X-WOPIMaxExpectedSize header.
		$res = $row->getWopiForToken($token);
		if ($res == false) {
			$this->logger->debug('wopiGetFile(): getWopiForToken() failed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$file = $this->fileService->getFileHandle($fileId, $res['owner'], $res['editor']);
		if (!$file) {
			$this->logger->warning('wopiGetFile(): Could not retrieve file', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DownloadResponse($this->request, $file);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * 
	 * The File contents endpoint provides access to update the contents of a file.
	 * 
	 * The PutFile operation updates a file’s binary contents.
	 */
	public function wopiPutFile(string $documentId): JSONResponse {
		$token = $this->request->getParam('access_token');

		list($fileId, , $version, ) = Helper::parseDocumentId($documentId);
		$this->logger->debug('PutFile: file {fileId}, version {version}, token {token}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version,
			'token' => $token
		]);

		$row = new Db\Wopi();
		$row->loadBy('token', $token);

		$res = $row->getWopiForToken($token);
		if ($res == false) {
			$this->logger->debug('PutFile: get token failed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$canWrite = $res['attributes'] & WOPI::ATTR_CAN_UPDATE;
		if (!$canWrite) {
			$this->logger->debug('PutFile: not allowed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// Retrieve wopi timestamp header
		$wopiHeaderTime = $this->request->getHeader('X-LOOL-WOPI-Timestamp');
		$this->logger->debug('PutFile: WOPI header timestamp: {wopiHeaderTime}', [
			'app' => $this->appName,
			'wopiHeaderTime' => $wopiHeaderTime
		]);


		// get owner and editor uid's
		$owner = $res['owner'];
		$editor = $res['editor'];

		$file = $this->fileService->getFileHandle($fileId, $owner, $editor);
		if (!$file) {
			$this->logger->warning('PutFile: Could not retrieve file', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		// Handle wopiHeaderTime
		if (!$wopiHeaderTime) {
			$this->logger->debug('PutFile: X-LOOL-WOPI-Timestamp absent. Saving file.', ['app' => $this->appName]);
		} elseif ($wopiHeaderTime != Helper::toISO8601($file->getMTime())) {
			$this->logger->debug('PutFile: Document timestamp mismatch ! WOPI client says mtime {headerTime} but storage says {storageTime}', [
				'app' => $this->appName,
				'headerTime' => $wopiHeaderTime,
				'storageTime' => Helper::toISO8601($file->getMtime())
			]);
			// Tell WOPI client about this conflict.
			return new JSONResponse(['LOOLStatusCode' => self::LOOL_STATUS_DOC_CHANGED], Http::STATUS_CONFLICT);
		}

		// Read the contents of the file from the POST body and store.
		$content = \fopen('php://input', 'r');
		$this->logger->debug(
			'PutFile: storing file {fileId}, editor: {editor}, owner: {owner}.',
			[
				'app' => $this->appName,
				'fileId' => $fileId,
				'editor' => $editor,
				'owner' => $owner
			]
		);
		$file->putContent($content);

		$this->logger->debug('PutFile: mtime', ['app' => $this->appName]);

		$mtime = $file->getMtime();

		return new JSONResponse([
			'status' => 'success',
			'LastModifiedTime' => Helper::toISO8601($mtime)
		], Http::STATUS_OK);
	}

	/**
	 * The Files endpoint operation PutFileRelative. 
	 */
	public function wopiPutFileRelative(string $documentId): JSONResponse {
		$token = $this->request->getParam('access_token');

		list($fileId, , $version, ) = Helper::parseDocumentId($documentId);
		$this->logger->debug('PutFileRelative: file {fileId}, version {version}, token {token}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version,
			'token' => $token]);

		$row = new Db\Wopi();
		$row->loadBy('token', $token);

		$res = $row->getWopiForToken($token);
		if ($res == false) {
			$this->logger->debug('PutFileRelative: get token failed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$canWrite = $res['attributes'] & WOPI::ATTR_CAN_UPDATE;
		if (!$canWrite) {
			$this->logger->debug('PutFileRelative: not allowed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// get owner and editor uid's
		$owner = $res['owner'];
		$editor = $res['editor'];

		// Retrieve suggested target
		$suggested = $this->request->getHeader('X-WOPI-SuggestedTarget');
		$suggested = \iconv('utf-7', 'utf-8', $suggested);
		
		$file = $this->fileService->getFileHandle($fileId, $owner, $editor);

		if (!$file) {
			$this->logger->warning('PutFileRelative: could not retrieve file', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$path = '';
		if ($suggested[0] === '.') {
			$path = \dirname($file->getPath()) . '/New File' . $suggested;
		} elseif ($suggested[0] !== '/') {
			$path = \dirname($file->getPath()) . '/' . $suggested;
		} else {
			$path = $this->rootFolder->getUserFolder($editor)->getPath() . $suggested;
		}

		if ($path === '') {
			return new JSONResponse([
				'status' => 'error',
				'message' => 'Cannot create the file'
			], Http::STATUS_BAD_REQUEST);
		}

		// create the folder first
		if (!$this->rootFolder->nodeExists(\dirname($path))) {
			$this->rootFolder->newFolder(\dirname($path));
		}

		try {
			$view = new View('/' . $editor . '/files');
			$view->verifyPath($path, $suggested);
		} catch (InvalidPathException $e) {
			return new JSONResponse([
				'status' => 'error',
				'message' => $this->l10n->t('Invalid filename'),
			], Http::STATUS_BAD_REQUEST);
		}

		// create a unique new file
		$path = $this->rootFolder->getNonExistingName($path);
		$file = $this->rootFolder->newFile($path);
		$file = $this->fileService->getFileHandle($file->getId(), $owner, $editor);
		if (!$file) {
			$this->logger->warning('PutFileRelative: could not retrieve file', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		// Read the contents of the file from the POST body and store.
		$content = \fopen('php://input', 'r');

		$file->putContent($content);
		$mtime = $file->getMtime();

		$this->logger->debug(
			'PutFileRelative: storing file {fileId}, editor: {editor}, owner: {owner}, mtime: {mtime}.',
			[
			'app' => $this->appName,
			'fileId' => $fileId,
			'editor' => $editor,
			'owner' => $owner,
			'mtime' => $mtime
			]
		);

		// we should preserve the original PostMessageOrigin
		// otherwise this will change it to serverHost after save-as
		// then we can no longer know the outer frame's origin.
		$row = new Wopi();
		$row->loadBy('token', $token);
		$res = $row->getWopiForToken($token);
		$serverHost = $res['server_host'] ? $res['server_host'] : $this->request->getServerProtocol() . '://' . $this->request->getServerHost();

		// Continue editing
		$attributes = WOPI::ATTR_CAN_VIEW | WOPI::ATTR_CAN_UPDATE | WOPI::ATTR_CAN_PRINT;
		// generate a token for the new file
		$tokenArray = $row->generateToken($file->getId(), 0, $attributes, $serverHost, $owner, $editor);

		$wopi = 'index.php/apps/richdocuments/wopi/files/' . $file->getId() . '_' . $this->settings->getSystemValue('instanceid') . '?access_token=' . $tokenArray['access_token'];
		$url = $this->urlGenerator->getAbsoluteURL($wopi);

		return new JSONResponse([ 'Name' => $file->getName(), 'Url' => $url ], Http::STATUS_OK);
	}

}
