<?php

declare(strict_types=1);

namespace Zeroseven\PagebasedBlog\EventListener;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Zeroseven\Pagebased\Event\StructuredDataEvent;
use Zeroseven\Pagebased\Exception\TypeException;
use Zeroseven\Pagebased\Utility\CastUtility;
use Zeroseven\Pagebased\Utility\SettingsUtility;

class ExtendStructuredDataEvent
{
    protected ?ContentObjectRenderer $contentObjectRenderer = null;

    protected function getContentObjectRenderer(): ContentObjectRenderer
    {
        return $this->contentObjectRenderer ?? $this->contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
    }

    protected function forceAbsoluteUrl(mixed $parameter = null): ?string
    {
        try {
            return empty($parameter) ? null : $this->getContentObjectRenderer()->typoLink_URL([
                'parameter' => CastUtility::string($parameter),
                'forceAbsoluteUrl' => true
            ]);
        } catch (TypeException $e) {
            return null;
        }
    }

    protected function getRootPageUri(int $currentPageId): ?string
    {
        try {
            $rootPageUid = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($currentPageId)->getRootPageId();
        } catch (SiteNotFoundException $e) {
            return null;
        }

        return $this->forceAbsoluteUrl($rootPageUid);
    }

    /** @throws ResourceDoesNotExistException */
    public function __invoke(StructuredDataEvent $event): void
    {
        $registration = $event->getRegistration();
        $uid = $event->getUid();
        $extensionName = $registration?->getExtensionName();

        if ($extensionName !== 'pagebased_blog' || !$registration || !$uid) {
            return;
        }

        // TODO: Keep an eye on issues in cached Frontend scope 
        try {
            $post = $registration->getObject()->getRepositoryClass()->findByUid($uid);
        } catch (\Exception $e) {
            return;
        }

        $event->addPropertyType('', [
            '@context' => 'https://schema.org/',
            'headline' => $post->getTitle(),
            'url' => $this->forceAbsoluteUrl($post->getUid())
        ], 'BlogPosting');

        if ($identifier = SettingsUtility::getExtensionConfiguration($registration, 'structuredData.identifier')) {
            $event->addPropertyType('identifier', [
                'name' => $identifier,
                'value' => (string)$post->getUid()
            ], 'PropertyValue');
        } else {
            $settings = SettingsUtility::getExtensionConfiguration($registration);
            $settings['structuredData']['identifier'] = uniqid($registration->getExtensionName() . '-', false);
            GeneralUtility::makeInstance(ExtensionConfiguration::class)->set($registration->getExtensionName(), $settings);
        }

        if ($image = $post->getFirstImage()) {
            // @extensionScannerIgnoreLine
            $event->addProperty('image', $image);
        }

        if ($description = $post->getDescription()) {
            $event->addProperty('description', $description);
        }

        if ($dateModified = $post->getLastChangeDate() ?? $post->getCreateDate()) {
            $event->addProperty('dateModified', $dateModified->format('Y-m-d'));
        }

        if ($datePosted = ($post->getDate() ?? ($post->getAccessStartDate() ?? $post->getCreateDate()))) {
            $event->addProperty('datePublished', $datePosted->format('Y-m-d'));
        }

        if ($author = $post->getContact()) {
            $event->addPropertyType('author', [
                'name' => $author->getFullName(),
                'knowsAbout' => $author->getExpertise(),
                'image' => $author->getImage(),
                'sameAs' => [
                    $this->forceAbsoluteUrl($author->getTwitter()),
                    $this->forceAbsoluteUrl($author->getXing()),
                    $this->forceAbsoluteUrl($author->getLinkedin()),
                    $this->forceAbsoluteUrl($author->getPageLink())
                ]
            ], 'Person');
        }

        if (($publisher = SettingsUtility::getPluginConfiguration($registration, 'settings.structuredData.publisher')) && !empty($publisher['name'])) {
            $event->addPropertyType('publisher', [
                'name' => $publisher['name'],
                'url' => $publisher['url'] ?? $this->getRootPageUri($post->getUid()),
            ], 'Organization');

            if ($logo = $publisher['logo'] ?? null) {
                if (file_exists($absolutePath = GeneralUtility::getFileAbsFileName($logo))) {
                    $event->addPropertyType('publisher.logo', ['url' => $this->forceAbsoluteUrl(PathUtility::getAbsoluteWebPath($absolutePath))], 'ImageObject');
                } else {
                    throw new ResourceDoesNotExistException('Creating structured data of the blog post failed. The organization logo ("' . $logo . '") does not exist.', 1689283412);
                }
            }
        }
    }
}
