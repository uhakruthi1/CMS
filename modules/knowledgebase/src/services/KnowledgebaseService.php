<?php

namespace modules\knowledgebase\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet as GlobalSetElement;
use craft\elements\Tag;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Entries as EntriesField;
use craft\fields\PlainText;
use craft\fields\Tags as TagsField;
use craft\fields\Table;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\EntryType;
use craft\models\TagGroup;
use craft\models\Section;
use craft\models\Section_SiteSettings;

class KnowledgebaseService extends Component
{
    public function ensureContentModel(): void
    {
        $summaryField = $this->ensurePlainTextField('kbSummary', 'Article Summary', 'Short description shown on list pages.');
        $bodyField = $this->ensureLongTextField('kbBody', 'Article Body');

        $tagGroup = $this->ensureTagGroup('knowledgeTags', 'Knowledge Tags');
        $tagsField = $this->ensureTagsField('kbTags', 'Article Tags', $tagGroup);

        $faqAnswerField = $this->ensureLongTextField('faqAnswer', 'Answer');

        $faqCategorySection = $this->ensureSection('faqCategories', 'FAQ Categories', Section::TYPE_STRUCTURE, template: null, uriFormat: null, enableUrls: false, maxLevels: 2);
        $faqSection = $this->ensureSection('faq', 'FAQ', Section::TYPE_CHANNEL, 'faq/_entry', 'faq/{slug}');
        $knowledgeBaseSection = $this->ensureSection('knowledgeBase', 'Knowledge Base', Section::TYPE_CHANNEL, 'kb/_entry', 'kb/{slug}');

        $faqCategoryField = $this->ensureEntriesField('faqCategory', 'Category', $faqCategorySection, 1);
        $relatedArticlesField = $this->ensureEntriesField('kbRelatedArticles', 'Related Articles', $knowledgeBaseSection, null, true);

        $this->configureEntryType($knowledgeBaseSection, 'knowledgeBaseArticle', 'Knowledge Base Article', [
            $summaryField,
            $bodyField,
            $tagsField,
            $relatedArticlesField,
        ]);

        $this->configureEntryType($faqSection, 'faqItem', 'FAQ Item', [
            $faqCategoryField,
            $faqAnswerField,
        ]);

        $this->configureEntryType($faqCategorySection, 'faqCategory', 'FAQ Category', []);

        $this->ensureSiteChromeGlobals();
    }

    public function seed(): void
    {
        $siteId = Craft::$app->get('sites')->getPrimarySite()->id;
        $tagGroupHandle = 'knowledgeTags';

        $categoriesData = [
            ['title' => 'Getting Started', 'slug' => 'getting-started'],
            ['title' => 'Account & Security', 'slug' => 'account-security'],
            ['title' => 'Operations & Support', 'slug' => 'operations-support'],
        ];

        $categoryEntries = [];
        foreach ($categoriesData as $category) {
            $categoryEntries[$category['slug']] = $this->upsertEntry('faqCategories', $category['slug'], [
                'title' => $category['title'],
                'siteId' => $siteId,
            ]);
        }

        $articles = [
            [
                'title' => 'Onboarding Playbook for Customer Support',
                'slug' => 'onboarding-playbook',
                'summary' => 'Step-by-step guide to ramp new agents inside Craft CMS.',
                'body' => '<p>Use structured modules, live shadowing, and FAQ audits to onboard faster. Pin every resource to the Knowledge Base to keep answers centralized.</p>',
                'tags' => ['onboarding', 'support', 'playbook'],
                'related' => ['customer-escalations', 'knowledge-capture'],
            ],
            [
                'title' => 'Knowledge Capture Cadence',
                'slug' => 'knowledge-capture',
                'summary' => 'Weekly ritual that keeps the FAQ current for ops teams.',
                'body' => '<p>Schedule a 30-minute capture session after sprint reviews. Tag updates by squad and publish in under 24 hours.</p>',
                'tags' => ['ops', 'process'],
                'related' => ['onboarding-playbook'],
            ],
            [
                'title' => 'De-escalation Workflow for Tier 2',
                'slug' => 'customer-escalations',
                'summary' => 'Reusable steps and macros to calm high-risk tickets.',
                'body' => '<p>Blend templated responses, verified troubleshooting, and real-time Slack alerts so escalations never stall.</p>',
                'tags' => ['support', 'customer'],
                'related' => ['onboarding-playbook'],
            ],
        ];

        $articleEntries = [];
        foreach ($articles as $article) {
            $entry = $this->upsertEntry('knowledgeBase', $article['slug'], [
                'title' => $article['title'],
                'siteId' => $siteId,
                'fields' => [
                    'kbSummary' => $article['summary'],
                    'kbBody' => $article['body'],
                    'kbTags' => $this->ensureTagIds($tagGroupHandle, $article['tags'], $siteId),
                ],
            ]);
            $articleEntries[$article['slug']] = $entry;
        }

        foreach ($articles as $article) {
            $entry = $articleEntries[$article['slug']];
            $relatedIds = [];
            foreach ($article['related'] as $slug) {
                if (isset($articleEntries[$slug])) {
                    $relatedIds[] = $articleEntries[$slug]->id;
                }
            }

            $entry->setFieldValue('kbRelatedArticles', $relatedIds);
            Craft::$app->elements->saveElement($entry);
        }

        $faqs = [
            [
                'title' => 'How do I publish a new article?',
                'slug' => 'publish-article',
                'answer' => '<p>Navigate to Knowledge Base → New Entry, add your Summary + Body, tag it, and hit Publish. Editors can only edit existing entries.</p>',
                'category' => 'Getting Started',
            ],
            [
                'title' => 'Who can update policies?',
                'slug' => 'update-policies',
                'answer' => '<p>Only Admin users can edit policy entries. Editors can save drafts for review.</p>',
                'category' => 'Account & Security',
            ],
            [
                'title' => 'How do I report duplicate FAQs?',
                'slug' => 'duplicate-faqs',
                'answer' => '<p>Use the “Flag duplicate” action on the FAQ page or @mention #knowledge-ops in Slack.</p>',
                'category' => 'Operations & Support',
            ],
        ];

        foreach ($faqs as $faq) {
            $categoryEntry = $categoryEntries[StringHelper::toKebabCase($faq['category'])] ?? null;
            if (!$categoryEntry) {
                continue;
            }

            $this->upsertEntry('faq', $faq['slug'], [
                'title' => $faq['title'],
                'siteId' => $siteId,
                'fields' => [
                    'faqAnswer' => $faq['answer'],
                    'faqCategory' => [$categoryEntry->id],
                ],
            ]);
        }
    }

    private function ensurePlainTextField(string $handle, string $name, string $instructions = ''): PlainText
    {
        $fields = Craft::$app->get('fields');
        $field = $fields->getFieldByHandle($handle) ?? new PlainText();
        $field->handle = $handle;
        $field->name = $name;
        $field->instructions = $instructions;
        $field->multiline = false;
        $fields->saveField($field);
        return $field;
    }

    private function ensureLongTextField(string $handle, string $name): PlainText
    {
        $fields = Craft::$app->get('fields');
        $field = $fields->getFieldByHandle($handle) ?? new PlainText();
        $field->handle = $handle;
        $field->name = $name;
        $field->multiline = true;
        $field->charLimit = null;
        $fields->saveField($field);
        return $field;
    }

    private function ensureEntriesField(string $handle, string $name, Section $section, ?int $limit = null, bool $allowSelfRelations = false): EntriesField
    {
        $fields = Craft::$app->get('fields');
        $field = $fields->getFieldByHandle($handle) ?? new EntriesField();
        $field->handle = $handle;
        $field->name = $name;
        $field->maxRelations = $limit;
        $field->allowSelfRelations = $allowSelfRelations;
        $field->sources = ['section:' . $section->uid];
        $fields->saveField($field);
        return $field;
    }

    private function ensureTagsField(string $handle, string $name, TagGroup $tagGroup): TagsField
    {
        $fields = Craft::$app->get('fields');
        $field = $fields->getFieldByHandle($handle) ?? new TagsField();
        $field->handle = $handle;
        $field->name = $name;
        $field->targetSiteId = null;
        $field->source = 'taggroup:' . $tagGroup->uid;
        $fields->saveField($field);
        return $field;
    }

    private function ensureTagGroup(string $handle, string $name): TagGroup
    {
        $tags = Craft::$app->get('tags');
        $group = $tags->getTagGroupByHandle($handle);
        if ($group) {
            return $group;
        }

        $group = new TagGroup();
        $group->handle = $handle;
        $group->name = $name;
        $tags->saveTagGroup($group);
        return $group;
    }

    private function ensureSection(string $handle, string $name, string $type, ?string $template, ?string $uriFormat, bool $enableUrls = true, int $maxLevels = 1): Section
    {
        $sectionsService = Craft::$app->getEntries();
        $section = $sectionsService->getSectionByHandle($handle);

        if (!$section) {
            $section = new Section();
        }

        $section->handle = $handle;
        $section->name = $name;
        $section->type = $type;
        $section->enableVersioning = true;
        if ($type === Section::TYPE_STRUCTURE) {
            $section->maxLevels = $maxLevels;
        }

        $siteSettings = [];
        foreach (Craft::$app->get('sites')->getAllSites() as $site) {
            $settings = new Section_SiteSettings();
            $settings->siteId = $site->id;
            $settings->enabledByDefault = true;
            $settings->hasUrls = $enableUrls;
            $settings->uriFormat = $uriFormat;
            $settings->template = $template;
            $siteSettings[$site->id] = $settings;
        }

        $section->setSiteSettings($siteSettings);

        $section->setEntryTypes($section->getEntryTypes());

        if (!$sectionsService->saveSection($section, false)) {
            throw new \RuntimeException('Unable to save section ' . $handle . ': ' . json_encode($section->getErrors()));
        }
        return $section;
    }

    private function configureEntryType(Section $section, string $handle, string $name, array $fields): void
    {
        $entryTypes = $section->getEntryTypes();
        $entryType = $entryTypes[0] ?? new EntryType();
        $entryType->handle = $handle;
        $entryType->name = $name;
        $entryType->hasTitleField = true;

        $layout = new FieldLayout();
        $layout->type = Entry::class;

        $tab = new FieldLayoutTab();
        $tab->name = 'Content';
        $tab->sortOrder = 1;
        $tab->uid = StringHelper::UUID();
        $tab->setLayout($layout);

        $elements = [];
        $titleElement = Craft::createObject([
            'class' => EntryTitleField::class,
            'uid' => StringHelper::UUID(),
        ]);
        $elements[] = $titleElement;

        foreach ($fields as $field) {
            if (!$field) {
                continue;
            }
            $elements[] = Craft::createObject([
                'class' => CustomField::class,
                'fieldUid' => $field->uid,
                'required' => false,
                'uid' => StringHelper::UUID(),
            ]);
        }

        $tab->setElements($elements);
        $layout->setTabs([$tab]);
        $entryType->setFieldLayout($layout);

        Craft::$app->getEntries()->saveEntryType($entryType);

        $section->setEntryTypes([$entryType]);
        Craft::$app->getEntries()->saveSection($section, false);
    }

    private function ensureTagIds(string $groupHandle, array $tags, int $siteId): array
    {
        $ids = [];
        foreach ($tags as $tagName) {
            $slug = StringHelper::toKebabCase($tagName);
            $tag = Tag::find()
                ->group($groupHandle)
                ->siteId($siteId)
                ->slug($slug)
                ->one();

            if (!$tag) {
                $tag = new Tag();
                $tag->groupId = Craft::$app->get('tags')->getTagGroupByHandle($groupHandle)->id;
                $tag->siteId = $siteId;
                $tag->title = $tagName;
                $tag->slug = $slug;
                Craft::$app->get('elements')->saveElement($tag);
            }

            $ids[] = $tag->id;
        }

        return $ids;
    }

    private function upsertEntry(string $sectionHandle, string $slug, array $config): Entry
    {
        $siteId = $config['siteId'];

        $entry = Entry::find()
            ->section($sectionHandle)
            ->siteId($siteId)
            ->slug($slug)
            ->status(null)
            ->one();

        if (!$entry) {
            $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
            $entryType = $section->getEntryTypes()[0];
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId = $entryType->id;
            $entry->siteId = $siteId;
            $entry->slug = $slug;
        }

        $entry->title = $config['title'];
        $entry->enabled = true;

        if (!empty($config['fields'])) {
            foreach ($config['fields'] as $handle => $value) {
                $entry->setFieldValue($handle, $value);
            }
        }

        if (!Craft::$app->get('elements')->saveElement($entry)) {
            throw new \RuntimeException('Failed to save entry: ' . json_encode($entry->getErrors()));
        }

        return $entry;
    }

    private function ensureSiteChromeGlobals(): void
    {
        $logoField = $this->ensurePlainTextField('siteLogoText', 'Logo Text', 'Brand text shown in the nav bar.');
        $navLinksField = $this->ensureLinkTableField('siteNavLinks', 'Navigation Links');
        $navCtaLabelField = $this->ensurePlainTextField('siteNavCtaLabel', 'Nav CTA Label');
        $navCtaUrlField = $this->ensurePlainTextField('siteNavCtaUrl', 'Nav CTA URL');
        $footerLinksField = $this->ensureLinkTableField('siteFooterLinks', 'Footer Links');
        $footerSocialField = $this->ensureLinkTableField('siteFooterSocial', 'Social Links');

        $globalSet = $this->ensureGlobalSet('siteChrome', 'Site Chrome', [
            $logoField,
            $navLinksField,
            $navCtaLabelField,
            $navCtaUrlField,
            $footerLinksField,
            $footerSocialField,
        ]);

        $this->seedSiteChrome($globalSet);
    }

    private function ensureLinkTableField(string $handle, string $name): Table
    {
        $columns = [
            'col1' => [
                'heading' => 'Label',
                'handle' => 'label',
                'type' => 'singleline',
            ],
            'col2' => [
                'heading' => 'URL',
                'handle' => 'url',
                'type' => 'url',
            ],
        ];

        return $this->ensureTableField($handle, $name, $columns);
    }

    private function ensureTableField(string $handle, string $name, array $columns): Table
    {
        $fields = Craft::$app->get('fields');
        $field = $fields->getFieldByHandle($handle) ?? new Table();
        $field->handle = $handle;
        $field->name = $name;
        $field->columns = $columns;
        $fields->saveField($field);
        return $field;
    }

    /**
     * @param PlainText|TagsField|EntriesField|Table $field
     * @return CustomField
     */
    private function createFieldLayoutElement($field): CustomField
    {
        return Craft::createObject([
            'class' => CustomField::class,
            'fieldUid' => $field->uid,
            'required' => false,
            'uid' => StringHelper::UUID(),
        ]);
    }

    private function ensureGlobalSet(string $handle, string $name, array $fields): GlobalSetElement
    {
        $globals = Craft::$app->get('globals');
        $globalSet = $globals->getSetByHandle($handle) ?? new GlobalSetElement();
        $globalSet->handle = $handle;
        $globalSet->name = $name;

        $layout = new FieldLayout();
        $layout->type = GlobalSetElement::class;
        $tab = new FieldLayoutTab();
        $tab->name = 'Content';
        $tab->uid = StringHelper::UUID();
        $tab->setLayout($layout);

        $elements = [];
        foreach ($fields as $field) {
            if (!$field) {
                continue;
            }
            $elements[] = $this->createFieldLayoutElement($field);
        }

        $tab->setElements($elements);
        $layout->setTabs([$tab]);
        $globalSet->setFieldLayout($layout);

        $globals->saveSet($globalSet);

        return $globalSet;
    }

    private function seedSiteChrome(GlobalSetElement $globalSet): void
    {
        $updated = false;

        if (!$globalSet->getFieldValue('siteLogoText')) {
            $globalSet->setFieldValue('siteLogoText', 'Craft Components');
            $updated = true;
        }

        if (!$globalSet->getFieldValue('siteNavLinks')) {
            $globalSet->setFieldValue('siteNavLinks', [
                ['label' => 'Components', 'url' => '#components'],
                ['label' => 'Knowledge Base', 'url' => '/kb'],
                ['label' => 'FAQ', 'url' => '/faq'],
                ['label' => 'Docs', 'url' => '/docs'],
            ]);
            $updated = true;
        }

        if (!$globalSet->getFieldValue('siteNavCtaLabel')) {
            $globalSet->setFieldValue('siteNavCtaLabel', 'View Docs');
            $updated = true;
        }

        if (!$globalSet->getFieldValue('siteNavCtaUrl')) {
            $globalSet->setFieldValue('siteNavCtaUrl', '/docs');
            $updated = true;
        }

        if (!$globalSet->getFieldValue('siteFooterLinks')) {
            $globalSet->setFieldValue('siteFooterLinks', [
                ['label' => 'GitHub', 'url' => 'https://github.com'],
                ['label' => 'Docs', 'url' => '/docs'],
                ['label' => 'Deploy', 'url' => '#deploy'],
            ]);
            $updated = true;
        }

        if (!$globalSet->getFieldValue('siteFooterSocial')) {
            $globalSet->setFieldValue('siteFooterSocial', [
                ['label' => 'Twitter', 'url' => 'https://twitter.com'],
                ['label' => 'LinkedIn', 'url' => 'https://linkedin.com'],
            ]);
            $updated = true;
        }

        if ($updated) {
            Craft::$app->get('elements')->saveElement($globalSet);
        }
    }
}
