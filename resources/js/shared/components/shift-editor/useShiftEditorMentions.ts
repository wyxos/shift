import type { Editor } from '@tiptap/core';
import { ref, type Ref } from 'vue';
import type { MentionCandidate, MentionIdentity } from './types';

type MentionProps = {
    enableMentions: boolean;
    mentionCandidates: MentionCandidate[];
    slashCommands: string[];
};

type MentionEmit = {
    (event: 'mention-query', value: string): void;
    (event: 'mention-add-request', candidate: MentionCandidate): void;
    (event: 'slash-command', command: string): void;
};

export function mentionKey(candidate: Pick<MentionCandidate, 'kind' | 'id'>): string {
    return `${candidate.kind}:${String(candidate.id)}`;
}

export function useShiftEditorMentions(props: MentionProps, editor: Ref<Editor | undefined>, emit: MentionEmit) {
    const mentionQuery = ref<string | null>(null);
    const mentionRange = ref<{ from: number; to: number } | null>(null);
    const mentionActiveIndex = ref(0);
    const pendingCollaboratorAdditions = ref(new Map<string, MentionCandidate>());
    let consumingSlashCommand = false;

    function closeMentionSuggestions(): void {
        mentionQuery.value = null;
        mentionRange.value = null;
        mentionActiveIndex.value = 0;
    }

    function refreshMentionSuggestions(editorInstance: Editor): void {
        if (!props.enableMentions) {
            closeMentionSuggestions();
            return;
        }

        const selection = editorInstance.state.selection;
        const from = selection.$from;

        if (!selection.empty || from.parent.type.name !== 'paragraph') {
            closeMentionSuggestions();
            return;
        }

        const before = from.parent.textBetween(0, from.parentOffset, '\uFFFC', '\uFFFC');
        const match = before.match(/(?:^|\s)@([^\s@]{0,80})$/);

        if (!match) {
            closeMentionSuggestions();
            return;
        }

        const query = match[1] ?? '';
        mentionQuery.value = query;
        mentionRange.value = {
            from: selection.from - query.length - 1,
            to: selection.from,
        };
        mentionActiveIndex.value = 0;
        emit('mention-query', query);
    }

    function consumeSlashCommand(editorInstance: Editor, allowWithoutTrailingSpace = false): boolean {
        if (consumingSlashCommand || props.slashCommands.length === 0) return false;

        const selection = editorInstance.state.selection;
        const from = selection.$from;
        if (!selection.empty || from.parent.type.name !== 'paragraph') return false;

        const before = from.parent.textBetween(0, from.parentOffset, '\uFFFC', '\uFFFC');
        const typedCommand = allowWithoutTrailingSpace ? before : before.endsWith(' ') ? before.slice(0, -1) : '';
        if (!typedCommand.startsWith('/')) return false;

        const command = typedCommand.slice(1);
        if (!props.slashCommands.includes(command)) return false;

        consumingSlashCommand = true;
        editorInstance
            .chain()
            .command(({ tr }) => {
                tr.delete(selection.from - before.length, selection.from);
                return true;
            })
            .run();
        consumingSlashCommand = false;
        closeMentionSuggestions();
        emit('slash-command', command);

        return true;
    }

    function insertMention(candidate: MentionCandidate, addToTask = false): void {
        const range = mentionRange.value;
        if (!editor.value || !range) return;

        editor.value
            .chain()
            .focus()
            .deleteRange(range)
            .insertContent([
                {
                    type: 'shiftMention',
                    attrs: {
                        kind: candidate.kind,
                        identity: candidate.id,
                        label: candidate.name,
                    },
                },
                { type: 'text', text: ' ' },
            ])
            .run();

        if (addToTask) {
            const additions = new Map(pendingCollaboratorAdditions.value);
            additions.set(mentionKey(candidate), candidate);
            pendingCollaboratorAdditions.value = additions;
        }

        closeMentionSuggestions();
    }

    function selectMentionCandidate(candidate: MentionCandidate): void {
        if (candidate.isCollaborator) {
            insertMention(candidate);
            return;
        }

        emit('mention-add-request', candidate);
    }

    function confirmMentionAddition(candidate: MentionCandidate): void {
        insertMention(candidate, true);
    }

    function collectMentionIdentities(): MentionIdentity[] {
        const identities = new Map<string, MentionIdentity>();

        editor.value?.state.doc.descendants((node) => {
            if (node.type.name !== 'shiftMention') return true;

            const kind = node.attrs.kind;
            const id = node.attrs.identity;
            if (!['internal', 'external'].includes(kind) || id === null || id === undefined || String(id).trim() === '') return true;

            const identity = { kind, id } as MentionIdentity;
            identities.set(mentionKey(identity), identity);
            return true;
        });

        return Array.from(identities.values());
    }

    function collectCollaboratorAdditions(mentions: MentionIdentity[]): MentionIdentity[] {
        const mentionKeys = new Set(mentions.map(mentionKey));

        return Array.from(pendingCollaboratorAdditions.value.values())
            .filter((candidate) => mentionKeys.has(mentionKey(candidate)))
            .map(({ kind, id }) => ({ kind, id }));
    }

    function moveMentionSelection(direction: 1 | -1): void {
        mentionActiveIndex.value = (mentionActiveIndex.value + direction + props.mentionCandidates.length) % props.mentionCandidates.length;
    }

    function resetMentions(): void {
        pendingCollaboratorAdditions.value = new Map();
        closeMentionSuggestions();
    }

    return {
        closeMentionSuggestions,
        collectCollaboratorAdditions,
        collectMentionIdentities,
        confirmMentionAddition,
        consumeSlashCommand,
        mentionActiveIndex,
        mentionQuery,
        moveMentionSelection,
        refreshMentionSuggestions,
        resetMentions,
        selectMentionCandidate,
    };
}
