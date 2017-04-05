<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH
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


namespace OCA\Market;

use OC\App\DependencyAnalyzer;
use OC\App\Platform;
use OCP\App\IAppManager;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Util;
use function Symfony\Component\Debug\Tests\testHeader;

class MarketService {

	/** @var array */
	private $apps;
	/** @var ICacheFactory */
	private $cacheFactory;
	/** @var IAppManager */
	private $appManager;
	/** @var IConfig */
	private $config;
	/** @var string */
	private $storeUrl;
	/** @var array */
	private $categories;

	/**
	 * Service constructor.
	 *
	 * @param IAppManager $appManager
	 * @param IConfig $config
	 * @param ICacheFactory $cacheFactory
	 */
	public function __construct(IAppManager $appManager, IConfig $config, ICacheFactory $cacheFactory) {
		$storeUrl = $config->getSystemValue('appstoreurl', 'https://marketplace.owncloud.com');

		$this->appManager = $appManager;
		$this->config = $config;
		$this->storeUrl = $storeUrl;
		$this->cacheFactory = $cacheFactory;
	}

	/**
	 * Install an app for the given app id
	 * @param string $appId
	 */
	public function installApp($appId) {

		$info = $this->getInstalledAppInfo($appId);
		if (!is_null($info)) {
			throw new \InvalidArgumentException('App is already installed');
		}

		// download package
		$package = $this->downloadPackage($appId);
		$this->appManager->installApp($package);
	}

	private function downloadPackage($appId) {

		$data = $this->getAppInfo($appId);
		if (empty($data)) {
			throw new \Exception('Unknown app');
		}

		$version = $this->getPlatformVersion();
		$release = array_filter($data['releases'], function($element) use ($version) {
			$platformMin = $element['platformMin'];
			$platformMax = $element['platformMax'];
			$tooSmall = $this->compare($version, $platformMin, '<');
			$tooBig = $this->compare($version, $platformMax, '>');

			return $tooSmall === false && $tooBig === false;
		});
		if (empty($release)) {
			throw new \Exception('No matching version');
		}
		$release = $release[0];
		$downloadLink = $release['download'];

		$pathInfo = pathinfo($downloadLink);
		$extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
		$path = \OC::$server->getTempManager()->getTemporaryFile($extension);
		$this->httpGet($downloadLink, ['save_to' => $path]);

		return $path;
	}

	/**
	 * Checks if the app with the given app id is installed
	 *
	 * @param string $appId
	 * @return bool
	 */
	public function isAppInstalled($appId) {
		$info = $this->getInstalledAppInfo($appId);
		return !is_null($info);
	}

	/**
	 * Returns the version for the app if an update is available
	 *
	 * @param string $appId
	 * @return bool|string
	 */
	public function getAvailableUpdateVersion($appId) {
		$info = $this->getInstalledAppInfo($appId);
		if (is_null($info)) {
			throw new \InvalidArgumentException("App ($appId) is not installed");
		}
		$marketInfo = $this->getAppInfo($appId);
		if (is_null($marketInfo)) {
			throw new \InvalidArgumentException("App ($appId) is not known at the marketplace.");
		}
		$releases = $marketInfo['releases'];
		$currentVersion = (string) $info['version'];
		$releases = array_filter($releases, function($r) use ($currentVersion) {
			$marketVersion = $r['version'];
			return version_compare($marketVersion, $currentVersion, '>');
		});
		if (!empty($releases)) {
			return $releases[0]['version'];
		}
		return false;
	}

	private function getAppInfo($appId) {
		$data = $this->getApps();
		$data = array_filter($data, function($element) use ($appId) {
			return $element['id'] === $appId;
		});
		if (empty($data)) {
			return null;
		}
		return $data[0];
	}

	/**
	 * @param string $appId
	 * @return array|null
	 */
	public function getInstalledAppInfo($appId) {
		$apps = $this->appManager->getAllApps();
		foreach ($apps as $app) {
			$info = $this->appManager->getAppInfo($app);
			if (isset($info['id']) && $info['id'] === $appId) {
				return $info;
			}
		}

		return null;
	}

	/**
	 * Update the app
	 *
	 * @param string $appId
	 */
	public function updateApp($appId) {
		$info = $this->getInstalledAppInfo($appId);
		if (is_null($info)) {
			throw new \InvalidArgumentException("App $appId is not installed");
		}

		// download package
		$package = $this->downloadPackage($appId);
		$this->appManager->updateApp($package);
	}

	/**
	 * Verify if all requirements are met
	 *
	 * @param [] $appInfo
	 * @return []
	 */
	public function getMissingDependencies($appInfo) {
		// bad hack - should use OCP
		$l10n = \OC::$server->getL10N('settings');
		$dependencyAnalyzer = new DependencyAnalyzer(new Platform($this->config), $l10n);

		return $dependencyAnalyzer->analyze($appInfo);
	}

	/**
	 * Get apps which need to be updated
	 *
	 * @return array
	 */
	public function getUpdates() {
		$result = [];
		$apps = $this->appManager->getAllApps();
		foreach ($apps as $app) {
			$info = $this->appManager->getAppInfo($app);
			if (isset($info['id'])) {
				try {
					$appId = $info['id'];
					$newVersion = $this->getAvailableUpdateVersion($appId);
					if ($newVersion) {
						$result[$app] = [
							'version' => $newVersion,
							'id' => $appId
						];
					}
				} catch (\InvalidArgumentException $ex) {
					// ignore exceptions thrown by getAvailableUpdateVersion
				}
			}
		}

		return $result;
	}

	/**
	 * Truncates both versions to the lowest common version, e.g.
	 * 5.1.2.3 and 5.1 will be turned into 5.1 and 5.1,
	 * 5.2.6.5 and 5.1 will be turned into 5.2 and 5.1
	 * @param string $first
	 * @param string $second
	 * @return string[] first element is the first version, second element is the
	 * second version
	 */
	private function normalizeVersions($first, $second) {
		$first = explode('.', $first);
		$second = explode('.', $second);

		// get both arrays to the same minimum size
		$length = min(count($second), count($first));
		$first = array_slice($first, 0, $length);
		$second = array_slice($second, 0, $length);

		return [implode('.', $first), implode('.', $second)];
	}

	/**
	 * Parameters will be normalized and then passed into version_compare
	 * in the same order they are specified in the method header
	 * @param string $first
	 * @param string $second
	 * @param string $operator
	 * @return bool result similar to version_compare
	 */
	private function compare($first, $second, $operator) {
		// we can't normalize versions if one of the given parameters is not a
		// version string but null. In case one parameter is null normalization
		// will therefore be skipped
		if ($first !== null && $second !== null) {
			list($first, $second) = $this->normalizeVersions($first, $second);
		}

		return version_compare($first, $second, $operator);
	}

	private function getPlatformVersion() {
		$v = Util::getVersion();
		return join('.', $v);
	}

	private function getApps() {
		$version = $this->getPlatformVersion();
		list($version,) = $this->normalizeVersions($version, '1.2.3');
		if (!is_null($this->apps)) {
			return $this->apps;
		}

		return $this->queryData("apps_$version", "/api/v1/platform/$version/apps.json");
	}

	/**
	 * @param string $path
	 * @param array $options
	 * @return \OCP\Http\Client\IResponse
	 */
	private function httpGet($path, $options = []) {
		$apiKey = $this->config->getSystemValue('marketplace.key', null);
		if ($apiKey !== null) {
			$options = array_merge([
				'headers' => ['Authorization' => "apikey: $apiKey"]
			], $options);
		}
		$client = \OC::$server->getHTTPClientService()->newClient();
		$response = $client->get($path, $options);
		return $response;
	}

	public function listApps($category = null) {
		$apps = $this->getApps();
		if ($category !== null) {
			$apps = array_filter($apps, function ($app) use ($category) {
				return in_array($category, $app['categories']);
			});
		}
		return $apps;
	}

	public function getCategories() {
		if ($this->categories !== null) {
			return $this->categories;
		}

		return $this->queryData('categories', "/api/v1/categories.json");
	}

	private function queryData($key, $uri) {
		// read from cache
		if ($this->cacheFactory->isAvailable()) {
			$cache = $this->cacheFactory->create('ocmp');
			$data = $cache->get($key);
			return json_decode($data, true);
		}

		// ask the server
		$response = $this->httpGet($this->storeUrl . $uri);
		$data = $response->getBody();
		if ($this->cacheFactory->isAvailable()) {
			// cache if for a day - TODO: evaluate the response header
			$cache = $this->cacheFactory->create('ocmp');
			$cache->set($key, $data, 60*60*24);
		}
		return json_decode($data, true);

	}

}
