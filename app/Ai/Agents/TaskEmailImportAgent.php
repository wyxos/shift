<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class TaskEmailImportAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You turn forwarded support or project emails into a reviewable SHIFT task draft.',
            'Extract only facts present in the email. Do not invent project behavior, API details, people, dates, or reproduction steps.',
            'Keep the title short and action-oriented.',
            'Choose priority as low, medium, or high from the email content. Use medium when uncertain.',
            'Return description_html as concise valid HTML for a rich text task editor.',
            'Use paragraphs and bullet lists. Do not include Markdown or code fences.',
            'Mention missing information only in missing_details, not as invented facts.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(160)->required(),
            'priority' => $schema->string()->enum(['low', 'medium', 'high'])->required(),
            'description_html' => $schema->string()->required(),
            'missing_details' => $schema->array()->items($schema->string()->max(160))->required(),
        ];
    }
}
