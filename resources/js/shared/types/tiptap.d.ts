import '@tiptap/core';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        imageUpload: {
            insertFiles: (files: File[]) => ReturnType;
            typeText: (text: string) => ReturnType;
        };
    }
}
