<?php
namespace Blocks;

/**
 *
 */
class UpdatesService extends BaseApplicationComponent
{
	private $_updateModel;

	/**
	 * @param $blocksReleases
	 * @return bool
	 */
	public function criticalBlocksUpdateAvailable($blocksReleases)
	{
		foreach ($blocksReleases as $blocksRelease)
		{
			if ($blocksRelease->critical)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $plugins
	 * @return bool
	 */
	public function criticalPluginUpdateAvailable($plugins)
	{
		foreach ($plugins as $plugin)
		{
			if ($plugin->status == PluginVersionUpdateStatus::UpdateAvailable && count($plugin->releases) > 0)
			{
				foreach ($plugin->releases as $release)
				{
					if ($release->critical)
					{
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * @return bool
	 */
	public function isUpdateInfoCached()
	{
		return (isset($this->_updateModel) || blx()->fileCache->get('updateinfo') !== false);
	}

	/**
	 * @return int
	 */
	public function getTotalNumberOfAvailableUpdates()
	{
		if ($this->isUpdateInfoCached())
		{
			$updateModel = $this->getUpdates();
			$count = 0;

			if ($updateModel->blocks->versionUpdateStatus == VersionUpdateStatus::UpdateAvailable)
			{
				if (isset($updateModel->blocks->releases) && count($updateModel->blocks->releases) > 0)
				{
					$count++;
				}
			}

			if (isset($updateModel->plugins))
			{
				foreach ($updateModel->plugins as $plugin)
				{
					if ($plugin->status == PluginVersionUpdateStatus::UpdateAvailable)
					{
						if (isset($plugin->releases) && count($plugin->releases) > 0)
						{
							$count++;
						}
					}
				}
			}

			if (isset($updateModel->packages) && count($updateModel->packages) > 0)
			{
				$count++;
			}

			return $count;
		}
	}

	/**
	 * @return mixed
	 */
	public function isCriticalUpdateAvailable()
	{
		if ((isset($this->_updateModel) && $this->_updateModel->blocks->criticalUpdateAvailable))
		{
			return true;
		}

		return false;
	}

	/**
	 * @return mixed
	 */
	public function isManualUpdateRequired()
	{
		if ((isset($this->_updateModel) && $this->_updateModel->blocks->manualUpdateRequired))
		{
			return true;
		}

		return false;
	}

	/**
	 * @param bool $forceRefresh
	 * @return mixed
	 */
	public function getUpdates($forceRefresh = false)
	{
		if (!isset($this->_updateModel) || $forceRefresh)
		{
			$updateModel = false;

			if (!$forceRefresh)
			{
				// get the update info from the cache if it's there
				$updateModel = blx()->fileCache->get('updateinfo');
			}

			// fetch it if it wasn't cached, or if we're forcing a refresh
			if ($forceRefresh || $updateModel === false)
			{
				$etModel = $this->check();

				if ($etModel == null)
				{
					$updateModel = new UpdateModel();
					$errors[] = Blocks::t('An error occurred when trying to determine if an update is available. Please try again shortly. If the error persists, please contact <a href="mailto://support@blockscms.com">support@pixelandtonic.com</a>.');
					$updateModel->errors = $errors;
				}
				else
				{
					$updateModel = $etModel->data;

					// cache it and set it to expire according to config
					blx()->fileCache->set('updateinfo', $updateModel);
				}
			}

			$this->_updateModel = $updateModel;
		}

		return $this->_updateModel;
	}

	/**
	 * @return bool
	 */
	public function flushUpdateInfoFromCache()
	{
		Blocks::log('Flushing update info from cache.', \CLogger::LEVEL_INFO);

		if (IOHelper::clearFolder(blx()->path->getCompiledTemplatesPath()) && IOHelper::clearFolder(blx()->path->getCachePath()))
		{
			return true;
		}

		return false;
	}

	/**
	 * @param $version
	 * @param $build
	 * @param $releaseDate
	 * @return bool
	 */
	public function setNewBlocksInfo($version, $build, $releaseDate)
	{
		$info = InfoRecord::model()->find();
		$info->version = $version;
		$info->build = $build;
		$info->releaseDate = $releaseDate;

		if ($info->save())
		{
			return true;
		}

		return false;
	}

	/**
	 * @param $pluginHandle
	 * @return bool
	 */
	public function doPluginUpdate($pluginHandle)
	{
		$pluginUpdater = new PluginUpdater($pluginHandle);

		if ($pluginUpdater->start())
		{
			return true;
		}

		return false;
	}

	/**
	 * @return UpdateModel
	 */
	public function check()
	{
		$updateModel = new UpdateModel();
		$updateModel->blocks = new BlocksUpdateModel();
		$updateModel->plugins = array();

		$updateModel->blocks->localBuild = Blocks::getBuild();
		$updateModel->blocks->localVersion = Blocks::getVersion();

		$plugins = blx()->plugins->getPlugins();

		$pluginUpdateModels = array();

		foreach ($plugins as $plugin)
		{
			$pluginUpdateModel = new PluginUpdateModel();
			$pluginUpdateModel->class = $plugin->getClassHandle();
			$pluginUpdateModel->localVersion = $plugin->version;

			$pluginUpdateModels[$plugin->getClassHandle()] = $pluginUpdateModel;
		}

		$updateModel->plugins = $pluginUpdateModels;

		$etModel = blx()->et->check($updateModel);
		return $etModel;
	}

	/**
	 * @return bool
	 */
	public function enableMaintenanceMode()
	{
		// Not using Active Record here to prevent issues with turning the site on/off during a migration
		if (blx()->db->schema->getTable('{{info}}')->getColumn('maintenance'))
		{
			if (blx()->db->createCommand()->update('info', array('maintenance' => 1)) > 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @static
	 * @return bool
	 */
	public function disableMaintenanceMode()
	{
		// Not using Active Record here to prevent issues with turning the site on/off during a migration
		if (blx()->db->schema->getTable('{{info}}')->getColumn('maintenance'))
		{
			if (blx()->db->createCommand()->update('info', array('maintenance' => 0)) > 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks to see if Blocks can write to a defined set of folders/files that are needed for auto-update to work.
	 *
	 * @return array|null
	 */
	public function getUnwritableFolders()
	{
		$checkPaths = array(
			blx()->path->getAppPath(),
			blx()->path->getPluginsPath(),
		);

		$errorPath = null;

		foreach ($checkPaths as $writablePath)
		{
			if (!IOHelper::isWritable($writablePath))
			{
				$errorPath[] = IOHelper::getRealPath($writablePath);
			}
		}

		return $errorPath;
	}

	/**
	 * @param $manual
	 * @return array
	 */
	public function prepareUpdate($manual)
	{
		Blocks::log('Preparing to update Blocks.', \CLogger::LEVEL_INFO);

		try
		{
			$updater = new AppUpdater();

			// No need to get the latest update info if this is a manual update.
			if (!$manual)
			{
				$updater->getLatestUpdateInfo();
			}

			$updater->checkRequirements();

			Blocks::log('Finished preparing to update Blocks.', \CLogger::LEVEL_INFO);
			return array('success' => true);
		}
		catch (\Exception $e)
		{
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * @return array
	 */
	public function processUpdateDownload()
	{
		Blocks::log('Starting to process the update download.', \CLogger::LEVEL_INFO);

		try
		{
			$updater = new AppUpdater();
			$result = $updater->processDownload();
			$result['success'] = true;

			Blocks::log('Finished processing the update download.', \CLogger::LEVEL_INFO);
			return $result;
		}
		catch (\Exception $e)
		{
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * @param $uid
	 * @return array
	 */
	public function backupFiles($uid)
	{
		Blocks::log('Starting to backup files that need to be updated.', \CLogger::LEVEL_INFO);

		try
		{
			$updater = new AppUpdater();
			$updater->backupFiles($uid);

			Blocks::log('Finished backing up files.', \CLogger::LEVEL_INFO);
			return array('success' => true);
		}
		catch (\Exception $e)
		{
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * @param $uid
	 * @return array
	 */
	public function updateFiles($uid)
	{
		Blocks::log('Starting to update files.', \CLogger::LEVEL_INFO);

		try
		{
			$updater = new AppUpdater();
			$updater->updateFiles($uid);

			Blocks::log('Finished updating files.', \CLogger::LEVEL_INFO);
			return array('success' => true);
		}
		catch (\Exception $e)
		{
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * @param $uid
	 * @return array
	 */
	public function backupDatabase($uid)
	{
		Blocks::log('Starting to backup database.', \CLogger::LEVEL_INFO);

		try
		{
			$updater = new AppUpdater();
			$result = $updater->backupDatabase($uid);

			if (!$result)
			{
				Blocks::log('Did not backup database because there were no migrations to run.', \CLogger::LEVEL_INFO);
				return array('success' => true);
			}
			else
			{
				Blocks::log('Finished backing up database.', \CLogger::LEVEL_INFO);
				return array('success' => true, 'dbBackupPath' => $result);
			}
		}
		catch (\Exception $e)
		{
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * @param      $uid
	 * @param bool $dbBackupPath
	 * @return array
	 */
	public function updateDatabase($uid, $dbBackupPath = false)
	{
		Blocks::log('Starting to update the database.', \CLogger::LEVEL_INFO);

		try
		{
			$updater = new AppUpdater();
			$updater->updateDatabase($uid, $dbBackupPath);

			Blocks::log('Finished updating the database.', \CLogger::LEVEL_INFO);
			return array('success' => true);
		}
		catch (\Exception $e)
		{
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * @param $uid
	 * @return array
	 */
	public function updateCleanUp($uid)
	{
		Blocks::log('Starting to clean up after the update.', \CLogger::LEVEL_INFO);

		try
		{
			$updater = new AppUpdater();
			$updater->cleanUp($uid);

			Blocks::log('Finished cleaning up after the update.', \CLogger::LEVEL_INFO);
			return array('success' => true);
		}
		catch (\Exception $e)
		{
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * @param      $uid
	 * @param bool $dbBackupPath
	 */
	public function rollbackUpdate($uid, $dbBackupPath = false)
	{
		if ($dbBackupPath && blx()->config->get('backupDbOnUpdate') && blx()->config->get('restoreDbOnUpdateFailure'))
		{
			Blocks::log('Rolling back any database changes.', \CLogger::LEVEL_INFO);
			UpdateHelper::rollBackDatabaseChanges($dbBackupPath);
			Blocks::log('Done rolling back any database changes.', \CLogger::LEVEL_INFO);
		}

		// If uid !== false, it's an auto-update.
		if ($uid !== false)
		{
			Blocks::log('Rolling back any file changes.', \CLogger::LEVEL_INFO);
			UpdateHelper::rollBackFileChanges(UpdateHelper::getManifestData(UpdateHelper::getUnzipFolderFromUID($uid)));
			Blocks::log('Done rolling back any file changes.', \CLogger::LEVEL_INFO);
		}
	}
}
