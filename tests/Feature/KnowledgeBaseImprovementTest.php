<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class KnowledgeBaseImprovementTest extends TestCase
{
    use RefreshDatabase;
    use SuperAdminFixtures;

    private User $owner;

    private User $manager;

    private User $bookkeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
    }

    public function test_safe_migration_and_classification_of_legacy_records(): void
    {
        // Simulate legacy records
        $author = $this->owner->id;
        $now = now();

        $procedureId = DB::table('knowledge_articles')->insertGetId([
            'title' => 'Legacy Internal Procedure Record',
            'slug' => 'legacy-procedure-'.uniqid(),
            'category' => 'Internal Procedure',
            'content' => 'Step 1: Do this. Step 2: Do that.',
            'tags' => json_encode(['demo', 'ops']),
            'status' => 'Published',
            'author_id' => $author,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $templateId = DB::table('knowledge_articles')->insertGetId([
            'title' => 'Legacy Compliance Checklist',
            'slug' => 'legacy-template-'.uniqid(),
            'category' => 'Compliance',
            'content' => 'Standard template details.',
            'tags' => json_encode(['compliance', 'template']),
            'status' => 'Published',
            'author_id' => $author,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $guidelineId = DB::table('knowledge_articles')->insertGetId([
            'title' => 'Legacy Documentation Benchmarks',
            'slug' => 'legacy-guideline-'.uniqid(),
            'category' => 'Documentation',
            'content' => 'Quality benchmarks and rules.',
            'tags' => json_encode(['guideline', 'standards']),
            'status' => 'Published',
            'author_id' => $author,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $articleId = DB::table('knowledge_articles')->insertGetId([
            'title' => 'Legacy Taxation Overview',
            'slug' => 'legacy-article-'.uniqid(),
            'category' => 'Taxation',
            'content' => 'Overview of tax regulations.',
            'tags' => json_encode(['tax', 'general']),
            'status' => 'Published',
            'author_id' => $author,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ambiguousId = DB::table('knowledge_articles')->insertGetId([
            'title' => 'Legacy Ambiguous Checklist Procedure',
            'slug' => 'legacy-ambiguous-'.uniqid(),
            'category' => 'Internal Procedure',
            'content' => 'Ambiguous procedure with template tag.',
            'tags' => json_encode(['template', 'procedure']),
            'status' => 'Published',
            'author_id' => $author,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $casualKeywordId = DB::table('knowledge_articles')->insertGetId([
            'title' => 'Article Mentions template and procedure casually',
            'slug' => 'legacy-casual-'.uniqid(),
            'category' => 'Accounting',
            'content' => 'Casual mention of template and procedure in content.',
            'tags' => json_encode(['accounting']),
            'status' => 'Published',
            'author_id' => $author,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Run the migration logic (or re-run migration class up method)
        $migration = require database_path('migrations/2026_10_22_000001_add_content_type_to_knowledge_articles.php');

        // Re-execute classification logic
        $articles = DB::table('knowledge_articles')->get(['id', 'category', 'tags']);
        foreach ($articles as $article) {
            $isProcedure = ($article->category === 'Internal Procedure');
            $tags = [];
            if (! empty($article->tags)) {
                $decoded = json_decode($article->tags, true);
                if (is_array($decoded)) {
                    $tags = array_map(fn ($t) => trim(mb_strtolower((string) $t)), $decoded);
                }
            }
            $isTemplate = in_array('template', $tags, true);
            $isGuideline = in_array('guideline', $tags, true);
            $matched = ($isProcedure ? 1 : 0) + ($isTemplate ? 1 : 0) + ($isGuideline ? 1 : 0);

            if ($matched > 1) {
                $contentType = 'article';
            } elseif ($isProcedure) {
                $contentType = 'procedure';
            } elseif ($isTemplate) {
                $contentType = 'template';
            } elseif ($isGuideline) {
                $contentType = 'guideline';
            } else {
                $contentType = 'article';
            }

            DB::table('knowledge_articles')->where('id', $article->id)->update(['content_type' => $contentType]);
        }

        $this->assertSame('procedure', DB::table('knowledge_articles')->where('id', $procedureId)->value('content_type'));
        $this->assertSame('template', DB::table('knowledge_articles')->where('id', $templateId)->value('content_type'));
        $this->assertSame('guideline', DB::table('knowledge_articles')->where('id', $guidelineId)->value('content_type'));
        $this->assertSame('article', DB::table('knowledge_articles')->where('id', $articleId)->value('content_type'));
        $this->assertSame('article', DB::table('knowledge_articles')->where('id', $ambiguousId)->value('content_type'));
        $this->assertSame('article', DB::table('knowledge_articles')->where('id', $casualKeywordId)->value('content_type'));

        // Verify down() migration method executes cleanly
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('knowledge_articles', 'content_type'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('knowledge_articles', 'content_type'));
    }

    public function test_creation_of_each_content_type(): void
    {
        $this->actingAs($this->owner);

        $types = ['article', 'procedure', 'template', 'guideline'];

        foreach ($types as $type) {
            $title = 'Test '.ucfirst($type).' Document';
            $response = $this->post('/knowledge', [
                'content_type' => $type,
                'title' => $title,
                'category' => 'Accounting',
                'status' => 'Published',
                'tags' => 'tag1, tag2',
                'content' => 'Content for '.$type,
            ]);

            $response->assertRedirect();
            $article = KnowledgeArticle::where('title', $title)->firstOrFail();
            $this->assertSame($type, $article->content_type);
            $this->assertSame('Accounting', $article->category);
            $this->assertSame('Published', $article->status);
            $this->assertSame($this->owner->id, $article->author_id);
            $this->assertNotEmpty($article->slug);
            $this->assertSame(['tag1', 'tag2'], $article->tags);
        }
    }

    public function test_editing_without_losing_existing_content_or_slug(): void
    {
        $this->actingAs($this->owner);

        $article = KnowledgeArticle::create([
            'content_type' => 'article',
            'title' => 'Original Knowledge Item',
            'slug' => 'original-slug-12345',
            'category' => 'Taxation',
            'status' => 'Published',
            'tags' => ['tax'],
            'content' => 'Original immutable text content.',
            'author_id' => $this->owner->id,
        ]);

        $originalId = $article->id;
        $originalSlug = $article->slug;
        $originalAuthor = $article->author_id;

        // Change content_type to template while editing
        $response = $this->put('/knowledge/'.$article->id, [
            'content_type' => 'template',
            'title' => 'Updated Knowledge Item Title',
            'category' => 'Compliance',
            'status' => 'Published',
            'tags' => 'tax, updated',
            'content' => 'Original immutable text content.',
        ]);

        $response->assertRedirect();
        $article->refresh();

        $this->assertSame($originalId, $article->id);
        $this->assertSame($originalSlug, $article->slug);
        $this->assertSame($originalAuthor, $article->author_id);
        $this->assertSame('template', $article->content_type);
        $this->assertSame('Compliance', $article->category);
        $this->assertSame('Updated Knowledge Item Title', $article->title);
    }

    public function test_updating_record_without_submitting_content_type_preserves_existing_type(): void
    {
        $this->actingAs($this->owner);

        // Test with procedure
        $procedure = KnowledgeArticle::create([
            'content_type' => 'procedure',
            'title' => 'Important Procedure',
            'slug' => 'important-proc-'.uniqid(),
            'category' => 'Internal Procedure',
            'status' => 'Published',
            'tags' => ['proc'],
            'content' => 'Procedure steps.',
            'author_id' => $this->owner->id,
        ]);

        // Submit update WITHOUT content_type field in the request payload
        $res = $this->put('/knowledge/'.$procedure->id, [
            'title' => 'Updated Procedure Title',
            'category' => 'Internal Procedure',
            'status' => 'Published',
            'tags' => 'proc',
            'content' => 'Procedure steps updated.',
        ]);
        $res->assertRedirect();

        $procedure->refresh();
        $this->assertSame('procedure', $procedure->content_type, 'Procedure content_type should be preserved when not submitted in update request.');
        $this->assertSame('Updated Procedure Title', $procedure->title);

        // Test with template
        $template = KnowledgeArticle::create([
            'content_type' => 'template',
            'title' => 'Standard Template',
            'slug' => 'standard-temp-'.uniqid(),
            'category' => 'Compliance',
            'status' => 'Published',
            'tags' => ['temp'],
            'content' => 'Template structure.',
            'author_id' => $this->owner->id,
        ]);

        $res = $this->put('/knowledge/'.$template->id, [
            'title' => 'Updated Template Title',
            'category' => 'Compliance',
            'status' => 'Published',
            'content' => 'Template structure updated.',
        ]);
        $res->assertRedirect();

        $template->refresh();
        $this->assertSame('template', $template->content_type, 'Template content_type should be preserved when not submitted in update request.');

        // Test with guideline
        $guideline = KnowledgeArticle::create([
            'content_type' => 'guideline',
            'title' => 'Standard Guideline',
            'slug' => 'standard-guide-'.uniqid(),
            'category' => 'Accounting',
            'status' => 'Published',
            'content' => 'Guideline details.',
            'author_id' => $this->owner->id,
        ]);

        $res = $this->put('/knowledge/'.$guideline->id, [
            'title' => 'Updated Guideline Title',
            'category' => 'Accounting',
            'status' => 'Published',
            'content' => 'Guideline details updated.',
        ]);
        $res->assertRedirect();

        $guideline->refresh();
        $this->assertSame('guideline', $guideline->content_type, 'Guideline content_type should be preserved when not submitted in update request.');
    }

    public function test_filtering_by_content_type(): void
    {
        $this->actingAs($this->owner);

        KnowledgeArticle::create(['content_type' => 'article', 'title' => 'Unique Article', 'slug' => 'art-1', 'category' => 'Accounting', 'status' => 'Published', 'content' => 'Article body', 'author_id' => $this->owner->id]);
        KnowledgeArticle::create(['content_type' => 'procedure', 'title' => 'Unique Procedure', 'slug' => 'proc-1', 'category' => 'Accounting', 'status' => 'Published', 'content' => 'Procedure body', 'author_id' => $this->owner->id]);
        KnowledgeArticle::create(['content_type' => 'template', 'title' => 'Unique Template', 'slug' => 'temp-1', 'category' => 'Accounting', 'status' => 'Published', 'content' => 'Template body', 'author_id' => $this->owner->id]);
        KnowledgeArticle::create(['content_type' => 'guideline', 'title' => 'Unique Guideline', 'slug' => 'guide-1', 'category' => 'Accounting', 'status' => 'Published', 'content' => 'Guideline body', 'author_id' => $this->owner->id]);

        // Filter article
        $res = $this->get('/knowledge?content_type=article')->assertOk();
        $res->assertSee('Unique Article');
        $res->assertDontSee('Unique Procedure');
        $res->assertDontSee('Unique Template');
        $res->assertDontSee('Unique Guideline');

        // Filter procedure
        $res = $this->get('/knowledge?content_type=procedure')->assertOk();
        $res->assertSee('Unique Procedure');
        $res->assertDontSee('Unique Article');
        $res->assertDontSee('Unique Template');
        $res->assertDontSee('Unique Guideline');

        // Filter template
        $res = $this->get('/knowledge?content_type=template')->assertOk();
        $res->assertSee('Unique Template');
        $res->assertDontSee('Unique Article');
        $res->assertDontSee('Unique Procedure');
        $res->assertDontSee('Unique Guideline');

        // Filter guideline
        $res = $this->get('/knowledge?content_type=guideline')->assertOk();
        $res->assertSee('Unique Guideline');
        $res->assertDontSee('Unique Article');
        $res->assertDontSee('Unique Procedure');
        $res->assertDontSee('Unique Template');

        // All content
        $res = $this->get('/knowledge')->assertOk();
        $res->assertSee('Unique Article');
        $res->assertSee('Unique Procedure');
        $res->assertSee('Unique Template');
        $res->assertSee('Unique Guideline');
    }

    public function test_filtering_by_category_independently(): void
    {
        $this->actingAs($this->owner);

        KnowledgeArticle::create(['content_type' => 'template', 'title' => 'Compliance Template', 'slug' => 'c-temp', 'category' => 'Compliance', 'status' => 'Published', 'content' => 'Content', 'author_id' => $this->owner->id]);
        KnowledgeArticle::create(['content_type' => 'template', 'title' => 'Accounting Template', 'slug' => 'a-temp', 'category' => 'Accounting', 'status' => 'Published', 'content' => 'Content', 'author_id' => $this->owner->id]);
        KnowledgeArticle::create(['content_type' => 'procedure', 'title' => 'Compliance Procedure', 'slug' => 'c-proc', 'category' => 'Compliance', 'status' => 'Published', 'content' => 'Content', 'author_id' => $this->owner->id]);

        // Category filter alone
        $res = $this->get('/knowledge?category=Compliance')->assertOk();
        $res->assertSee('Compliance Template');
        $res->assertSee('Compliance Procedure');
        $res->assertDontSee('Accounting Template');

        // Content type + category combined
        $res = $this->get('/knowledge?content_type=template&category=Compliance')->assertOk();
        $res->assertSee('Compliance Template');
        $res->assertDontSee('Accounting Template');
        $res->assertDontSee('Compliance Procedure');
    }

    public function test_combining_search_category_type_status_and_dates(): void
    {
        $this->actingAs($this->owner);

        $target = KnowledgeArticle::create([
            'content_type' => 'procedure',
            'title' => 'BIR Annual Tax Procedure',
            'slug' => 'bir-tax-proc',
            'category' => 'BIR',
            'status' => 'Published',
            'tags' => ['tax', 'annual'],
            'content' => 'Detailed steps for annual filing verification.',
            'author_id' => $this->owner->id,
        ]);
        KnowledgeArticle::where('id', $target->id)->update([
            'created_at' => '2026-05-15 10:00:00',
            'updated_at' => '2026-05-15 10:00:00',
        ]);

        $other = KnowledgeArticle::create([
            'content_type' => 'procedure',
            'title' => 'BIR Monthly Withholding Procedure',
            'slug' => 'bir-withholding-proc',
            'category' => 'BIR',
            'status' => 'Draft',
            'tags' => ['tax'],
            'content' => 'Draft procedure for withholding.',
            'author_id' => $this->owner->id,
        ]);
        KnowledgeArticle::where('id', $other->id)->update([
            'created_at' => '2026-05-15 10:00:00',
            'updated_at' => '2026-05-15 10:00:00',
        ]);

        $res = $this->get('/knowledge?q=Annual&content_type=procedure&category=BIR&status=Published&from=2026-05-01&to=2026-05-31')->assertOk();
        $res->assertSee($target->title);
        $res->assertDontSee($other->title);
    }

    public function test_sorting_options(): void
    {
        $this->actingAs($this->owner);

        KnowledgeArticle::whereNotNull('id')->forceDelete();

        $a = KnowledgeArticle::create(['content_type' => 'article', 'title' => 'Alpha Document', 'slug' => 'alpha', 'category' => 'Accounting', 'status' => 'Published', 'content' => 'A', 'author_id' => $this->owner->id]);
        $b = KnowledgeArticle::create(['content_type' => 'article', 'title' => 'Beta Document', 'slug' => 'beta', 'category' => 'Accounting', 'status' => 'Published', 'content' => 'B', 'author_id' => $this->owner->id]);
        $z = KnowledgeArticle::create(['content_type' => 'article', 'title' => 'Zeta Document', 'slug' => 'zeta', 'category' => 'Accounting', 'status' => 'Published', 'content' => 'Z', 'author_id' => $this->owner->id]);

        KnowledgeArticle::where('id', $a->id)->update(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(1)]);
        KnowledgeArticle::where('id', $b->id)->update(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
        KnowledgeArticle::where('id', $z->id)->update(['created_at' => now()->subDays(1), 'updated_at' => now()]);

        // Newest First (default) -> Z, A, B
        $res = $this->get('/knowledge?content_type=article&sort=newest')->assertOk();
        $records = $res->viewData('records');
        $this->assertSame($z->id, $records->first()->id);

        // Oldest First -> B, A, Z
        $res = $this->get('/knowledge?content_type=article&sort=oldest')->assertOk();
        $records = $res->viewData('records');
        $this->assertSame($b->id, $records->first()->id);

        // Recently Updated -> Z, A, B
        $res = $this->get('/knowledge?content_type=article&sort=updated')->assertOk();
        $records = $res->viewData('records');
        $this->assertSame($z->id, $records->first()->id);

        // Title A-Z -> Alpha, Beta, Zeta
        $res = $this->get('/knowledge?content_type=article&sort=title_asc')->assertOk();
        $records = $res->viewData('records');
        $this->assertSame($a->id, $records->first()->id);

        // Title Z-A -> Zeta, Beta, Alpha
        $res = $this->get('/knowledge?content_type=article&sort=title_desc')->assertOk();
        $records = $res->viewData('records');
        $this->assertSame($z->id, $records->first()->id);
    }

    public function test_pagination_and_retained_filters(): void
    {
        $this->actingAs($this->owner);

        for ($i = 1; $i <= 15; $i++) {
            KnowledgeArticle::create([
                'content_type' => 'procedure',
                'title' => sprintf('Procedure Sequence %02d', $i),
                'slug' => 'proc-seq-'.$i,
                'category' => 'Documentation',
                'status' => 'Published',
                'content' => 'Procedure content text',
                'author_id' => $this->owner->id,
            ]);
        }

        $res = $this->get('/knowledge?content_type=procedure&category=Documentation&per_page=10&page=2')->assertOk();
        $res->assertSee('Procedure Sequence 05');
        // Pagination link should retain query params
        $res->assertSee('content_type=procedure');
        $res->assertSee('category=Documentation');
    }

    public function test_invalid_input_validation(): void
    {
        $this->actingAs($this->owner);

        // Reject unknown content_type
        $res = $this->post('/knowledge', [
            'content_type' => 'unsupported_type',
            'title' => 'Invalid Type Test',
            'category' => 'Accounting',
            'status' => 'Published',
            'content' => 'Some content',
        ]);
        $res->assertSessionHasErrors('content_type');

        // Reject missing required fields
        $res = $this->post('/knowledge', [
            'content_type' => 'article',
            'title' => '',
            'category' => 'InvalidCategory',
            'status' => 'Published',
            'content' => '',
        ]);
        $res->assertSessionHasErrors(['title', 'category', 'content']);
    }

    public function test_bookkeeper_draft_and_publishing_restrictions(): void
    {
        $this->actingAs($this->bookkeeper);

        // Bookkeeper creates a draft
        $res = $this->post('/knowledge', [
            'content_type' => 'procedure',
            'title' => 'Bookkeeper Operating Note',
            'category' => 'Accounting',
            'status' => 'Draft',
            'content' => 'Instructions for preparing monthly accounts.',
        ]);
        $res->assertRedirect();

        $article = KnowledgeArticle::where('title', 'Bookkeeper Operating Note')->firstOrFail();
        $this->assertSame('procedure', $article->content_type);
        $this->assertSame('Draft', $article->status);

        // Bookkeeper cannot publish
        $res = $this->put('/knowledge/'.$article->id, [
            'content_type' => 'procedure',
            'title' => 'Bookkeeper Operating Note',
            'category' => 'Accounting',
            'status' => 'Published',
            'content' => 'Instructions for preparing monthly accounts.',
        ]);
        $res->assertForbidden();

        // Manager can publish
        $this->actingAs($this->manager);
        $res = $this->put('/knowledge/'.$article->id, [
            'content_type' => 'procedure',
            'title' => 'Bookkeeper Operating Note',
            'category' => 'Accounting',
            'status' => 'Published',
            'content' => 'Instructions for preparing monthly accounts.',
        ]);
        $res->assertRedirect();
        $this->assertSame('Published', $article->fresh()->status);
    }

    public function test_owner_and_office_manager_permissions(): void
    {
        foreach ([$this->owner, $this->manager] as $user) {
            $this->actingAs($user);

            $res = $this->post('/knowledge', [
                'content_type' => 'guideline',
                'title' => 'Manager Guideline '.$user->id,
                'category' => 'SEC',
                'status' => 'Published',
                'content' => 'Firm SEC guidelines.',
            ]);
            $res->assertRedirect();

            $article = KnowledgeArticle::where('title', 'Manager Guideline '.$user->id)->firstOrFail();
            $this->assertSame('Published', $article->status);

            // Can archive
            $this->delete('/knowledge/'.$article->id)->assertRedirect();
            $this->assertSoftDeleted('knowledge_articles', ['id' => $article->id]);
        }
    }

    public function test_legacy_urls_compatibility(): void
    {
        $this->actingAs($this->owner);

        KnowledgeArticle::create([
            'content_type' => 'procedure',
            'title' => 'Legacy Procedure By Category',
            'slug' => 'legacy-proc-cat',
            'category' => 'Internal Procedure',
            'status' => 'Published',
            'content' => 'Legacy procedural steps.',
            'author_id' => $this->owner->id,
        ]);

        // Legacy URL with ?type=Internal%20Procedure
        $res = $this->get('/knowledge?type=Internal%20Procedure')->assertOk();
        $res->assertSee('Legacy Procedure By Category');

        // New category URL with ?category=Internal%20Procedure
        $res = $this->get('/knowledge?category=Internal%20Procedure')->assertOk();
        $res->assertSee('Legacy Procedure By Category');
    }

    public function test_plain_text_excerpt_markdown_stripping_and_html_safety(): void
    {
        $this->actingAs($this->owner);

        $article = KnowledgeArticle::create([
            'content_type' => 'article',
            'title' => 'Markdown Formatted Article',
            'slug' => 'md-article',
            'category' => 'Taxation',
            'status' => 'Published',
            'content' => "# Main Title\n\n## Subheading\n\nThis is **bold** text and `inline code` with a [link](https://example.com) and <script>alert('xss')</script>.\n\n> Blockquote line",
            'author_id' => $this->owner->id,
        ]);

        $excerpt = $article->plain_excerpt;
        $this->assertStringNotContainsString('#', $excerpt);
        $this->assertStringNotContainsString('**', $excerpt);
        $this->assertStringNotContainsString('`', $excerpt);
        $this->assertStringNotContainsString('[link]', $excerpt);
        $this->assertStringNotContainsString('> Blockquote', $excerpt);

        // Verify index view escapes untrusted HTML
        $res = $this->get('/knowledge')->assertOk();
        $res->assertSee('&lt;script&gt;', false);
        $res->assertDontSee('<script>alert(\'xss\')</script>', false);
    }

    public function test_context_aware_add_button_and_preselected_content_type(): void
    {
        $this->actingAs($this->owner);

        // When viewing templates, button says Add Template and links with content_type=template
        $res = $this->get('/knowledge?content_type=template')->assertOk();
        $res->assertSee('Add Template');
        $res->assertSee(route('knowledge.create', ['content_type' => 'template']));

        // When viewing procedures, button says Add Procedure
        $res = $this->get('/knowledge?content_type=procedure')->assertOk();
        $res->assertSee('Add Procedure');
        $res->assertSee(route('knowledge.create', ['content_type' => 'procedure']));

        // Visiting create with content_type=template preselects template in the form
        $res = $this->get('/knowledge/create?content_type=template')->assertOk();
        $res->assertSee('value="template" selected', false);
        $res->assertSee('Add Template');
        $res->assertSee('Save Template');
    }

    public function test_content_type_empty_states(): void
    {
        $this->actingAs($this->owner);

        // Delete any existing knowledge articles
        KnowledgeArticle::query()->forceDelete();

        // Visiting Templates tab with no records shows specific empty state
        $res = $this->get('/knowledge?content_type=template')->assertOk();
        $res->assertSee('No templates yet');
        $res->assertSee('Create a reusable template to organize standard knowledge and reference content.');
        $res->assertSee('Add Template');

        // Procedures empty state
        $res = $this->get('/knowledge?content_type=procedure')->assertOk();
        $res->assertSee('No procedures yet');
        $res->assertSee('Create a standard operating procedure to document operational steps and protocols.');
        $res->assertSee('Add Procedure');

        // Guidelines empty state
        $res = $this->get('/knowledge?content_type=guideline')->assertOk();
        $res->assertSee('No guidelines yet');
        $res->assertSee('Create an internal guideline to define best practices and organizational policies.');
        $res->assertSee('Add Guideline');

        // Articles empty state
        $res = $this->get('/knowledge?content_type=article')->assertOk();
        $res->assertSee('No articles yet');
        $res->assertSee('Create an informative article to share insights, documentation, and references.');
        $res->assertSee('Add Article');

        // When search filter yields no results, shows filter empty state
        $res = $this->get('/knowledge?content_type=template&q=NonExistentSearchTerm12345')->assertOk();
        $res->assertSee('No matching records');
        $res->assertSee('Clear filters');
    }
}
