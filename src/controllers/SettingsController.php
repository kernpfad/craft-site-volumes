<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\controllers;

use Craft;
use craft\web\Controller;
use kernpfad\sitevolumes\SiteVolumes;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('siteVolumes:manage');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $sites = Craft::$app->getSites()->getAllSites();
        $restrictionService = SiteVolumes::getInstance()?->restrictions;
        $restrictions = $restrictionService?->getAllRestrictions() ?? [];
        $defaultVolumeIds = $restrictionService?->getAllDefaultVolumeIds() ?? [];
        $installedSiblingPlugins = SiteVolumes::getInstance()?->compatibility->getInstalledSiblingPlugins() ?? [];

        return $this->renderTemplate('site-volumes/index.twig', [
            'volumes' => $volumes,
            'sites' => $sites,
            'restrictions' => $restrictions,
            'defaultVolumeIds' => $defaultVolumeIds,
            'installedSiblingPlugins' => $installedSiblingPlugins,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $sites = Craft::$app->getSites()->getAllSites();
        $siteIdsByVolume = $this->request->getBodyParam('siteIds', []);
        $defaultVolumeIdsBySite = $this->request->getBodyParam('defaultVolumeIds', []);
        $restrictionService = SiteVolumes::getInstance()?->restrictions;

        if ($restrictionService === null) {
            $this->setFailFlash(Craft::t('site-volumes', 'Plugin is not available.'));

            return $this->redirectToPostedUrl();
        }

        $proposedRestrictions = [];
        foreach ($volumes as $volume) {
            if ($volume->id === null) {
                continue;
            }

            $proposedRestrictions[$volume->id] = array_map('intval', $siteIdsByVolume[$volume->id] ?? []);
        }

        $siteIds = array_values(array_filter(array_map(static fn($site) => $site->id, $sites)));

        $starvedSiteIds = $restrictionService->findSitesWithNoAvailableVolume(
            $proposedRestrictions,
            $siteIds,
        );

        if ($starvedSiteIds !== []) {
            $starvedSiteNames = array_map(
                static fn($site) => $site->name,
                array_filter($sites, static fn($site) => in_array($site->id, $starvedSiteIds, true)),
            );

            $this->setFailFlash(Craft::t(
                'site-volumes',
                'Couldn’t save: {sites} would have no available volume left.',
                ['sites' => implode(', ', $starvedSiteNames)],
            ));

            return $this->redirectToPostedUrl();
        }

        $proposedDefaults = [];
        foreach ($sites as $site) {
            if ($site->id === null) {
                continue;
            }

            $raw = $defaultVolumeIdsBySite[$site->id] ?? null;
            $proposedDefaults[$site->id] = ($raw === null || $raw === '') ? null : (int)$raw;
        }

        $invalidDefaultSiteIds = $restrictionService->findSitesWithInvalidDefaultVolume(
            $proposedDefaults,
            $proposedRestrictions,
        );

        if ($invalidDefaultSiteIds !== []) {
            $invalidSiteNames = array_map(
                static fn($site) => $site->name,
                array_filter($sites, static fn($site) => in_array($site->id, $invalidDefaultSiteIds, true)),
            );

            $this->setFailFlash(Craft::t(
                'site-volumes',
                'Couldn’t save: the default upload volume for {sites} isn’t allowed on that site.',
                ['sites' => implode(', ', $invalidSiteNames)],
            ));

            return $this->redirectToPostedUrl();
        }

        foreach ($proposedDefaults as $siteId => $volumeId) {
            if ($volumeId !== null && Craft::$app->getVolumes()->getVolumeById($volumeId) === null) {
                $this->setFailFlash(Craft::t('site-volumes', 'Couldn’t save: one of the default upload volumes no longer exists.'));

                return $this->redirectToPostedUrl();
            }
        }

        foreach ($proposedRestrictions as $volumeId => $siteIds) {
            if (!$restrictionService->saveAllowedSites($volumeId, $siteIds)) {
                $this->setFailFlash(Craft::t('site-volumes', "Couldn't save volume restrictions."));

                return $this->redirectToPostedUrl();
            }
        }

        foreach ($proposedDefaults as $siteId => $volumeId) {
            if (!$restrictionService->saveDefaultVolume($siteId, $volumeId)) {
                $this->setFailFlash(Craft::t('site-volumes', "Couldn't save default upload volumes."));

                return $this->redirectToPostedUrl();
            }
        }

        $this->setSuccessFlash(Craft::t('site-volumes', 'Site volume settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
