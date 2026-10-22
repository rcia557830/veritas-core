<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table) {
            $table->string('content_type')->default('article')->after('slug')->index();
        });

        // Safe migration of existing records using conservative classification:
        // - Existing category exactly "Internal Procedure" can be classified as procedure.
        // - Existing exact "template" tag can identify a template.
        // - Existing exact "guideline" tag can identify a guideline.
        // - Otherwise classify as article.
        // - Handle conflicting or ambiguous classifications conservatively as article and log for review.
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
                Log::warning("KnowledgeArticle #{$article->id} has ambiguous/conflicting classification. Defaulting to article.", [
                    'id' => $article->id,
                    'category' => $article->category,
                    'tags' => $article->tags,
                ]);
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

            if ($contentType !== 'article') {
                DB::table('knowledge_articles')->where('id', $article->id)->update([
                    'content_type' => $contentType,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table) {
            $table->dropIndex(['content_type']);
            $table->dropColumn('content_type');
        });
    }
};
