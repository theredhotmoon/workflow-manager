<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0">
    <div v-if="show" class="fixed top-16 inset-x-0 bottom-0 z-40 flex items-center justify-center">
      <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
      <div ref="editorModalRef"
        role="dialog"
        aria-modal="true"
        aria-labelledby="editor-modal-title"
        class="relative w-full h-full overflow-y-auto bg-slate-900/98">
        <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
          <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
              <h1 id="editor-modal-title" class="text-2xl font-bold text-white">{{ isEdit ? 'Edit Procedure' : 'New Procedure' }}</h1>
              <span v-if="isEdit && hasDraft" class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">
                Draft
              </span>
              <span v-if="isEdit" class="text-[10px] text-slate-500 tabular-nums">v{{ originalVersion }}</span>
            </div>
            <button @click="handleClose" aria-label="Close editor" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition-all" title="Close">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          <!-- Draft banner -->
          <div v-if="isEdit && hasDraft" class="mb-6 p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl flex items-center justify-between">
            <p class="text-sm text-amber-300">
              <strong>Editing draft</strong> — changes won't affect the published version (v{{ originalVersion }}) until you publish.
            </p>
            <button @click="discardDraft" class="px-3 py-1.5 text-xs text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg hover:bg-red-500/20 transition-all">
              Discard Draft
            </button>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left: Editor (2/3) -->
            <div class="lg:col-span-2">
              <form @submit.prevent class="space-y-6">
                <!-- What went wrong with the last save or load (field errors sit next to their fields) -->
                <div v-if="formError" role="alert"
                  class="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-sm text-red-300">{{ formError }}</div>

                <!-- Title + Identifier -->
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                  <div class="sm:col-span-3">
                    <label for="procedure-editor-title" class="block text-sm font-medium text-slate-300 mb-1.5">Title <span class="text-red-400 ml-0.5">*</span></label>
                    <input id="procedure-editor-title" v-model="form.title" type="text" required placeholder="e.g. Password Reset via Database"
                      @blur="touchedTitle = true"
                      :aria-invalid="titleError ? 'true' : undefined"
                      :class="['w-full px-4 py-2.5 bg-white/5 border rounded-xl text-white text-sm focus:outline-none focus:ring-2 placeholder-slate-500',
                        titleError ? 'border-red-500/40 focus:ring-red-500/30' : 'border-white/10 focus:ring-indigo-500/50']" />
                    <p v-if="titleError" data-testid="title-error" class="mt-1 text-xs text-red-400">{{ titleError }}</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Identifier</label>
                    <input :value="form.identifier || 'Auto-generated'" disabled
                      class="w-full px-4 py-2.5 bg-white/3 border border-white/5 rounded-xl text-slate-500 text-sm" />
                  </div>
                </div>

                <!-- Purpose -->
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Purpose</label>
                  <textarea v-model="form.purpose" rows="2" placeholder="Brief explanation of what the employee will achieve..."
                    class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500 resize-none"></textarea>
                  <p v-if="serverError('purpose')" class="mt-1 text-xs text-red-400">{{ serverError('purpose') }}</p>
                </div>

                <!-- Requirements -->
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Requirements / Permissions / Tools</label>
                  <textarea v-model="form.requirements" rows="2" placeholder="Database access, admin panel..."
                    class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500 resize-none"></textarea>
                  <p v-if="serverError('requirements')" class="mt-1 text-xs text-red-400">{{ serverError('requirements') }}</p>
                </div>

                <!-- Owner + Tags -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Owner <span class="text-red-400 ml-0.5">*</span></label>
                    <select v-model="form.owner_id" required :disabled="isEdit"
                      @blur="touchedOwner = true"
                      :class="['w-full px-4 py-2.5 bg-white/5 border rounded-xl text-white text-sm focus:outline-none focus:ring-2 appearance-none disabled:opacity-60 disabled:cursor-not-allowed',
                        ownerError ? 'border-red-500/40 focus:ring-red-500/30' : 'border-white/10 focus:ring-indigo-500/50']">
                      <option value="" disabled class="bg-slate-800">Select owner...</option>
                      <option v-for="u in users" :key="u.id" :value="u.id" class="bg-slate-800">{{ u.name }}</option>
                    </select>
                    <p v-if="ownerError" class="mt-1 text-xs text-red-400">{{ ownerError }}</p>
                    <!-- Saving never changes the owner; the Owners tab records who changed it and why. -->
                    <p v-else-if="isEdit" class="mt-1 text-[10px] text-slate-500">Change the owner in the Owners tab.</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Tags</label>
                    <div class="flex flex-wrap gap-1.5 p-2.5 bg-white/5 border border-white/10 rounded-xl min-h-[42px]">
                      <button v-for="tag in allTags" :key="tag.id" @click.prevent="toggleTag(tag.id)" type="button"
                        :class="['px-2 py-0.5 rounded-full text-[10px] font-medium transition-all border cursor-pointer',
                          form.tag_ids.includes(tag.id) ? 'shadow-sm' : 'bg-white/5 text-slate-500 border-white/10']"
                        :style="form.tag_ids.includes(tag.id) ? { backgroundColor: tag.color + '25', color: tag.color, borderColor: tag.color + '50' } : {}">
                        {{ tag.name }}
                      </button>
                    </div>
                    <p v-if="serverError('tag_ids')" class="mt-1 text-xs text-red-400">{{ serverError('tag_ids') }}</p>
                  </div>
                </div>

                <!-- Rich Text Editor -->
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Content</label>
                  <div class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
                    <!-- Toolbar -->
                    <div v-if="editor" class="flex items-center flex-wrap gap-0.5 p-2 border-b border-white/10 bg-white/3">
                      <button type="button" v-for="btn in toolbarButtons" :key="btn.label"
                        @click="btn.action()"
                        :class="['p-1.5 rounded-lg text-xs font-medium transition-all',
                          btn.isActive?.() ? 'bg-indigo-500/20 text-indigo-400' : 'text-slate-400 hover:text-white hover:bg-white/10']"
                        :title="btn.label">
                        <span v-html="btn.icon"></span>
                      </button>
                      <div class="w-px h-5 bg-white/10 mx-1"></div>
                      <button type="button" @click="addImage" class="p-1.5 rounded-lg text-xs text-slate-400 hover:text-white hover:bg-white/10 transition-all" title="Insert image from clipboard">
                        🖼️
                      </button>
                    </div>
                    <!-- Editor content -->
                    <editor-content :editor="editor" class="tiptap-editor" />
                  </div>
                  <p v-if="serverError('content')" class="mt-1 text-xs text-red-400">{{ serverError('content') }}</p>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-between pt-2">
                  <button v-if="isEdit" type="button" @click="deleteProcedure"
                    class="px-4 py-2 text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-xl hover:bg-red-500/20 transition-all">
                    Delete Procedure
                  </button>
                  <div></div>
                  <div class="flex items-center gap-3">
                    <button type="button" @click="handleClose" class="px-4 py-2 text-sm text-slate-300 bg-white/5 border border-white/10 rounded-xl hover:bg-white/10 transition-all">Cancel</button>
                    <button v-if="isEdit" type="button" @click="saveDraft" :disabled="saving"
                      class="px-5 py-2 bg-white/5 text-amber-400 border border-amber-500/30 font-semibold rounded-xl text-sm hover:bg-amber-500/10 transition-all disabled:opacity-50">
                      {{ saving ? 'Saving...' : 'Save Draft' }}
                    </button>
                    <button type="button" @click="isEdit ? openPublishModal() : publishOrCreate()" :disabled="saving || (isEdit && unresolvedCount > 0)"
                      :title="isEdit && unresolvedCount > 0 ? 'Resolve all feedback first' : ''"
                      class="px-6 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl text-sm shadow-lg shadow-violet-500/25 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                      {{ saving ? 'Saving...' : (isEdit ? `Publish v${originalVersion + 1}` : 'Create') }}
                    </button>
                  </div>
                  <!-- Unresolved feedback warning -->
                  <div v-if="isEdit && unresolvedCount > 0" class="col-span-full">
                    <div class="bg-red-500/10 border border-red-500/20 rounded-xl p-3 flex items-start gap-2">
                      <svg class="w-4 h-4 text-red-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                      </svg>
                      <div>
                        <p class="text-xs font-semibold text-red-400">Cannot publish — {{ unresolvedCount }} unresolved feedback item{{ unresolvedCount > 1 ? 's' : '' }}</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Review each feedback in the right panel and mark it as <span class="text-emerald-400">accepted</span>, <span class="text-red-400">rejected</span>, or <span class="text-blue-400">deferred</span>, then click Save.</p>
                      </div>
                    </div>
                  </div>
                </div>
              </form>
            </div>

            <!-- Right: Feedback Review Panel (1/3) -->

        <!-- Publish Confirmation Modal -->
        <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
          enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
          <div v-if="showPublishModal" class="fixed inset-0 z-[60] flex items-center justify-center px-4">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showPublishModal = false"></div>
            <div ref="publishModalRef"
              role="dialog"
              aria-modal="true"
              aria-labelledby="publish-modal-title"
              class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-indigo-500/25 rounded-2xl shadow-2xl p-6">
              <h3 id="publish-modal-title" class="text-white font-bold text-lg mb-1">Publish v{{ originalVersion + 1 }}</h3>
              <p class="text-slate-400 text-sm mb-4">Describe what changed in this version.</p>
              <form @submit.prevent="publishOrCreate" class="space-y-4">
                <div v-if="publishError" role="alert"
                  class="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-sm text-red-300">{{ publishError }}</div>
                <div>
                  <label for="procedure-change-reason" class="block text-sm font-medium text-slate-300 mb-1.5">Reason for Changes <span class="text-red-400">*</span></label>
                  <textarea id="procedure-change-reason" v-model="changeReason" rows="3" required placeholder="e.g. Updated step 3 with new admin panel screenshots, fixed incorrect SQL query..."
                    :aria-invalid="serverError('change_reason') ? 'true' : undefined"
                    class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500 resize-none"></textarea>
                  <p v-if="serverError('change_reason')" data-testid="change-reason-error" class="mt-1 text-xs text-red-400">{{ serverError('change_reason') }}</p>
                </div>
                <div class="flex justify-end gap-3">
                  <button type="button" @click="showPublishModal = false" class="px-4 py-2 text-sm text-slate-300 bg-white/5 border border-white/10 rounded-xl hover:bg-white/10 transition-all">Cancel</button>
                  <button type="submit" :disabled="saving || !changeReason.trim()"
                    class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl text-sm shadow-lg disabled:opacity-50 disabled:cursor-not-allowed">{{ saving ? 'Publishing...' : `Publish v${originalVersion + 1}` }}</button>
                </div>
              </form>
            </div>
          </div>
        </Transition>
            <div v-if="isEdit" class="space-y-4">
              <div class="bg-white/5 border border-white/10 rounded-2xl p-4">
                <h3 class="text-white font-bold text-sm mb-3 flex items-center gap-2">
                  Feedback since v{{ originalVersion - 1 }}
                  <span v-if="pendingFeedbacks.length" class="px-1.5 py-0.5 text-[9px] bg-orange-500/15 text-orange-400 rounded-full">{{ pendingFeedbacks.length }}</span>
                </h3>
                <div v-if="!pendingFeedbacks.length" class="text-center text-slate-500 text-sm py-4">
                  No feedback to review.
                </div>
                <div v-else class="space-y-3 max-h-[60vh] overflow-y-auto pr-1">
                  <div v-for="fb in pendingFeedbacks" :key="fb.id"
                    :class="['border rounded-xl p-3 transition-all',
                      fb.resolution === 'accepted' ? 'bg-emerald-500/5 border-emerald-500/20' :
                      fb.resolution === 'rejected' ? 'bg-red-500/5 border-red-500/20' :
                      fb.resolution === 'deferred' ? 'bg-blue-500/5 border-blue-500/20' :
                      'bg-white/5 border-white/10']">
                    <div class="flex items-center justify-between mb-2">
                      <div class="flex items-center gap-2">
                        <span :class="['px-2 py-0.5 text-[9px] font-semibold uppercase rounded-full',
                          fb.type === 'error' ? 'bg-red-500/15 text-red-400 border border-red-500/20' : 'bg-blue-500/15 text-blue-400 border border-blue-500/20']">
                          {{ fb.type }}
                        </span>
                        <span class="text-[10px] text-slate-500">{{ fb.user?.name }}</span>
                      </div>
                      <span v-if="fb.resolution" :class="['px-2 py-0.5 text-[9px] font-semibold uppercase rounded-full',
                        fb.resolution === 'accepted' ? 'bg-emerald-500/15 text-emerald-400' :
                        fb.resolution === 'rejected' ? 'bg-red-500/15 text-red-400' :
                        'bg-blue-500/15 text-blue-400']">
                        {{ fb.resolution }}
                      </span>
                    </div>
                    <p class="text-sm text-slate-300 mb-2">{{ fb.description }}</p>
                    <p v-if="fb.proposed_changes" class="text-xs text-slate-400 mb-2 pl-3 border-l-2 border-white/10">{{ fb.proposed_changes }}</p>

                    <!-- Resolution controls (hidden once resolved) -->
                    <template v-if="!fb._resolved">
                      <div class="flex flex-wrap gap-1.5 mb-2">
                        <button v-for="res in ['accepted', 'rejected', 'deferred']" :key="res"
                          @click="fb._pendingResolution = fb._pendingResolution === res ? null : res"
                          :class="['px-2 py-1 text-[10px] font-medium rounded-lg border transition-all',
                            fb._pendingResolution === res ? 'ring-1 ring-offset-1 ring-offset-slate-800' : '',
                            res === 'accepted' ? 'text-emerald-400 border-emerald-500/20 hover:bg-emerald-500/10' + (fb._pendingResolution === res ? ' bg-emerald-500/15 ring-emerald-500/40' : '') :
                            res === 'rejected' ? 'text-red-400 border-red-500/20 hover:bg-red-500/10' + (fb._pendingResolution === res ? ' bg-red-500/15 ring-red-500/40' : '') :
                            'text-blue-400 border-blue-500/20 hover:bg-blue-500/10' + (fb._pendingResolution === res ? ' bg-blue-500/15 ring-blue-500/40' : '')]">
                          {{ res }}
                        </button>
                      </div>
                      <div class="flex gap-1.5">
                        <input v-model="fb._comment" type="text" placeholder="Add comment..."
                          @keyup.enter="submitFeedbackReview(fb)"
                          class="flex-1 px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
                        <button @click="submitFeedbackReview(fb)" :disabled="!fb._pendingResolution"
                          class="px-3 py-1.5 bg-indigo-500/20 text-indigo-400 border border-indigo-500/20 rounded-lg hover:bg-indigo-500/30 transition-all text-[10px] font-medium disabled:opacity-30 disabled:cursor-not-allowed"
                          title="Save review">
                          Save
                        </button>
                      </div>
                    </template>
                    <!-- Resolved state -->
                    <template v-else>
                      <p v-if="fb.owner_comment" class="text-[10px] text-slate-400 mt-1 italic">💬 {{ fb.owner_comment }}</p>
                      <p class="text-[9px] text-emerald-400 mt-1">✓ Resolved</p>
                    </template>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </main>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import Underline from '@tiptap/extension-underline';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { computed, onBeforeUnmount, reactive, ref, toRef, watch } from 'vue';
import { useRouter } from 'vue-router';
import { describeApiError, fieldError } from '../../apiErrors.js';
import api from '../../axios.js';
import { confirm } from '../../composables/useConfirm.js';
import { useFocusTrap } from '../../composables/useFocusTrap.js';
import { useLatestRequest } from '../../composables/useLatestRequest.js';
import { useToast } from '../../composables/useToast.js';
import { isAdmin } from '../../roles.js';
import { currentUser as authUser } from '../../router/index.js';

const props = defineProps({
  show: { type: Boolean, default: false },
  procedureId: { type: Number, default: null },
});

const emit = defineEmits(['close', 'saved']);

const router = useRouter();
const { showToast } = useToast();
const isEdit = computed(() => !!props.procedureId);
const saving = ref(false);
const users = ref([]);
const allTags = ref([]);
const hasDraft = ref(false);
const originalVersion = ref(1);
const allFeedbacks = ref([]);
const showPublishModal = ref(false);

// Focus traps
const editorModalRef = ref(null);
const publishModalRef = ref(null);
useFocusTrap(editorModalRef, toRef(props, 'show'), { onEscape: () => handleClose() });
useFocusTrap(publishModalRef, showPublishModal, {
  onEscape: () => {
    showPublishModal.value = false;
  },
});
const changeReason = ref('');
const loaded = ref(false);

const form = reactive({
  identifier: '',
  title: '',
  purpose: '',
  requirements: '',
  content: '',
  owner_id: '',
  tag_ids: [],
});

// Validation: the server's field errors from the last failed save win over the client checks.
const touchedTitle = ref(false);
const touchedOwner = ref(false);
const serverErrors = ref({});
const formError = ref(''); // summary of the last failed save or load, shown above the form
const publishError = ref(''); // a failed publish that isn't about a form field, shown in its dialog
const serverError = (name) => fieldError(serverErrors.value, name);
const titleError = computed(
  () =>
    serverError('title') || (touchedTitle.value && !form.title.trim() ? 'Title is required.' : ''),
);
const ownerError = computed(
  () =>
    serverError('owner_id') || (touchedOwner.value && !form.owner_id ? 'Owner is required.' : ''),
);

function clearErrors() {
  serverErrors.value = {};
  formError.value = '';
  publishError.value = '';
}

// Shows why a save failed and keeps everything the user typed. 401/419 are left to sessionExpiry.js
// (it sends the user to the login page). A field error under the publish dialog closes that dialog
// so the field is visible; the change reason is kept.
function showSaveError(err, fallback = 'Could not save, try again') {
  const e = describeApiError(err, fallback);
  if (e.silent) return;
  serverErrors.value = e.fieldErrors;
  const fields = Object.keys(e.fieldErrors);
  if (showPublishModal.value && fields.every((f) => f === 'change_reason')) {
    if (!fields.length) publishError.value = e.message;
    return;
  }
  showPublishModal.value = false;
  formError.value = e.message;
}

function showActionError(err, fallback) {
  const e = describeApiError(err, fallback);
  if (!e.silent) showToast(e.message, 'error', 6000);
}

// Unsaved changes: the form as last loaded or saved, compared with what is on screen now.
const savedForm = ref(null);
const formSnapshot = () => JSON.stringify({ ...form, tag_ids: [...form.tag_ids].sort() });
function markSaved() {
  savedForm.value = formSnapshot();
}
const isDirty = computed(() => savedForm.value !== null && formSnapshot() !== savedForm.value);

const pendingFeedbacks = computed(() => {
  const prevVersion = originalVersion.value - 1;
  return allFeedbacks.value.filter(
    (fb) =>
      (fb.status === 'open' || fb.status === 'reviewed') &&
      (fb.for_version === null || fb.for_version >= prevVersion),
  );
});

const unresolvedCount = computed(() => {
  return pendingFeedbacks.value.filter((fb) => !fb._resolved && !fb.resolution).length;
});

const editor = useEditor({
  extensions: [
    StarterKit,
    Image.configure({ inline: false, allowBase64: true }),
    Link.configure({ openOnClick: false }),
    Underline,
    Placeholder.configure({
      placeholder: 'Write procedure steps here... Paste images directly from clipboard.',
    }),
  ],
  content: '',
  editorProps: {
    attributes: { class: 'tiptap-editor-content' },
    handlePaste(view, event) {
      const items = event.clipboardData?.items;
      if (items) {
        for (const item of items) {
          if (item.type.startsWith('image/')) {
            event.preventDefault();
            const file = item.getAsFile();
            const reader = new FileReader();
            reader.onload = (e) => {
              editor.value?.chain().focus().setImage({ src: e.target.result }).run();
            };
            reader.readAsDataURL(file);
            return true;
          }
        }
      }
      return false;
    },
  },
  onUpdate: ({ editor: ed }) => {
    form.content = ed.getHTML();
  },
});

const toolbarButtons = computed(() => {
  if (!editor.value) return [];
  const e = editor.value;
  return [
    {
      label: 'Bold',
      icon: '<b>B</b>',
      action: () => e.chain().focus().toggleBold().run(),
      isActive: () => e.isActive('bold'),
    },
    {
      label: 'Italic',
      icon: '<i>I</i>',
      action: () => e.chain().focus().toggleItalic().run(),
      isActive: () => e.isActive('italic'),
    },
    {
      label: 'Underline',
      icon: '<u>U</u>',
      action: () => e.chain().focus().toggleUnderline().run(),
      isActive: () => e.isActive('underline'),
    },
    {
      label: 'Strikethrough',
      icon: '<s>S</s>',
      action: () => e.chain().focus().toggleStrike().run(),
      isActive: () => e.isActive('strike'),
    },
    {
      label: 'Heading 1',
      icon: 'H1',
      action: () => e.chain().focus().toggleHeading({ level: 1 }).run(),
      isActive: () => e.isActive('heading', { level: 1 }),
    },
    {
      label: 'Heading 2',
      icon: 'H2',
      action: () => e.chain().focus().toggleHeading({ level: 2 }).run(),
      isActive: () => e.isActive('heading', { level: 2 }),
    },
    {
      label: 'Heading 3',
      icon: 'H3',
      action: () => e.chain().focus().toggleHeading({ level: 3 }).run(),
      isActive: () => e.isActive('heading', { level: 3 }),
    },
    {
      label: 'Bullet List',
      icon: '•',
      action: () => e.chain().focus().toggleBulletList().run(),
      isActive: () => e.isActive('bulletList'),
    },
    {
      label: 'Numbered List',
      icon: '1.',
      action: () => e.chain().focus().toggleOrderedList().run(),
      isActive: () => e.isActive('orderedList'),
    },
    {
      label: 'Blockquote',
      icon: '"',
      action: () => e.chain().focus().toggleBlockquote().run(),
      isActive: () => e.isActive('blockquote'),
    },
    {
      label: 'Code',
      icon: '&lt;/&gt;',
      action: () => e.chain().focus().toggleCode().run(),
      isActive: () => e.isActive('code'),
    },
    {
      label: 'Code Block',
      icon: '{ }',
      action: () => e.chain().focus().toggleCodeBlock().run(),
      isActive: () => e.isActive('codeBlock'),
    },
  ];
});

function addImage() {
  const url = prompt('Image URL:');
  if (url) editor.value?.chain().focus().setImage({ src: url }).run();
}

function toggleTag(id) {
  const idx = form.tag_ids.indexOf(id);
  idx >= 0 ? form.tag_ids.splice(idx, 1) : form.tag_ids.push(id);
}

// Escape, the ✕ and Cancel all come here. With unsaved changes the user confirms first: the next
// open starts from the server's copy, so closing throws the changes away.
let askingToClose = false;
async function handleClose() {
  if (askingToClose) return;
  if (isDirty.value) {
    askingToClose = true;
    const ok = await confirm({
      title: 'Discard unsaved changes?',
      message: 'Your changes have not been saved. Close the editor and lose them?',
      confirmLabel: 'Discard changes',
      danger: true,
    });
    askingToClose = false;
    if (!ok) return;
  }
  emit('close');
}

function openPublishModal() {
  publishError.value = '';
  showPublishModal.value = true;
}

async function saveDraft() {
  if (saving.value) return;
  clearErrors();
  saving.value = true;
  try {
    await api.put(`/api/procedures/${props.procedureId}/draft`, form);
    hasDraft.value = true;
    markSaved();
    showToast('Draft saved');
  } catch (err) {
    showSaveError(err);
  } finally {
    saving.value = false;
  }
}

async function publishOrCreate() {
  if (saving.value) return;
  clearErrors();
  saving.value = true;
  try {
    if (isEdit.value) {
      // Save draft first, then publish with change_reason
      await api.put(`/api/procedures/${props.procedureId}/draft`, form);
      hasDraft.value = true;
      markSaved();
      const nextVersion = originalVersion.value + 1;
      await api.post(`/api/procedures/${props.procedureId}/publish`, {
        change_reason: changeReason.value,
      });
      showPublishModal.value = false;
      showToast(`Published v${nextVersion}`);
      emit('saved', props.procedureId);
    } else {
      const res = await api.post('/api/procedures', form);
      markSaved();
      showToast('Procedure created');
      emit('saved', res.data.id);
    }
  } catch (err) {
    showSaveError(err, isEdit.value ? 'Could not publish, try again' : 'Could not save, try again');
  } finally {
    saving.value = false;
  }
}

async function deleteProcedure() {
  const ok = await confirm({
    title: 'Delete Procedure',
    message: 'Delete this procedure permanently? This cannot be undone.',
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/procedures/${props.procedureId}`);
    showToast('Procedure deleted');
    emit('saved', null); // null signals deletion
  } catch (err) {
    showActionError(err, 'Could not delete the procedure, try again');
  }
}

async function discardDraft() {
  const ok = await confirm({
    title: 'Discard Draft',
    message: 'Discard all draft changes? This cannot be undone.',
    confirmLabel: 'Discard',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/procedures/${props.procedureId}/draft`);
    hasDraft.value = false;
  } catch (err) {
    showActionError(err, 'Could not discard the draft, try again');
    return;
  }
  showToast('Draft discarded');
  clearErrors();
  // Reload the published version into the form
  try {
    const res = await api.get(`/api/procedures/${props.procedureId}`);
    loadFromResponse(res.data);
  } catch (err) {
    showActionError(err, 'Could not reload the procedure, close and reopen the editor');
  }
}

async function submitFeedbackReview(fb) {
  if (!fb._pendingResolution) return;
  try {
    await api.put(`/api/procedure-feedbacks/${fb.id}`, {
      resolution: fb._pendingResolution,
      owner_comment: (fb._comment || '').trim() || null,
      status: 'resolved',
    });
    fb.resolution = fb._pendingResolution;
    fb.owner_comment = (fb._comment || '').trim() || null;
    fb.status = 'resolved';
    fb._resolved = true;
  } catch (err) {
    showActionError(err, 'Could not save the review, try again');
  }
}

function loadFromResponse(data) {
  originalVersion.value = data.version;
  hasDraft.value = data.has_draft;
  allFeedbacks.value = (data.feedbacks || []).map((fb) => ({
    ...fb,
    _comment: fb.owner_comment || '',
  }));

  if (data.has_draft) {
    Object.assign(form, {
      identifier: data.identifier,
      title: data.draft_title || data.title,
      purpose: data.draft_purpose ?? data.purpose ?? '',
      requirements: data.draft_requirements ?? data.requirements ?? '',
      content: data.draft_content ?? data.content ?? '',
      owner_id: data.owner_id,
      tag_ids: (data.tags || []).map((t) => t.id),
    });
  } else {
    Object.assign(form, {
      identifier: data.identifier,
      title: data.title,
      purpose: data.purpose || '',
      requirements: data.requirements || '',
      content: data.content || '',
      owner_id: data.owner_id,
      tag_ids: (data.tags || []).map((t) => t.id),
    });
  }
  // setContent fires onUpdate, which stores the editor's normalized HTML in form.content: take the
  // clean snapshot after it, or every freshly opened editor would count as changed.
  editor.value?.commands.setContent(form.content);
  markSaved();
}

function resetForm() {
  Object.assign(form, {
    identifier: '',
    title: '',
    purpose: '',
    requirements: '',
    content: '',
    owner_id: '',
    tag_ids: [],
  });
  touchedTitle.value = false;
  touchedOwner.value = false;
  hasDraft.value = false;
  originalVersion.value = 1;
  allFeedbacks.value = [];
  showPublishModal.value = false;
  changeReason.value = '';
  loaded.value = false;
  clearErrors();
  savedForm.value = null; // nothing to lose until the form is loaded
  editor.value?.commands.setContent('');
}

// Closing and reopening the editor mid-load starts a second load. Only the latest may fill the
// form, mark it loaded or close the modal on error — otherwise a stale response overwrites what the
// user already typed, or a stale failure closes the freshly opened editor.
const loadRequests = useLatestRequest();

async function loadData() {
  if (loaded.value) return;
  const token = loadRequests.begin();
  try {
    const [usersRes, tagsRes] = await Promise.all([
      api.get('/api/users'),
      api.get('/api/procedure-tags'),
    ]);
    if (!loadRequests.isCurrent(token)) return;
    users.value = usersRes.data;
    allTags.value = tagsRes.data;
  } catch (err) {
    if (!loadRequests.isCurrent(token)) return;
    const e = describeApiError(err, 'Could not load the owner list and tags, try again');
    if (!e.silent) formError.value = `Could not load the owner list and tags: ${e.message}`;
  }
  if (!loadRequests.isCurrent(token)) return;

  if (isEdit.value) {
    // Editing is admin-only (every procedure write route is); the views only offer it to admins.
    if (!isAdmin(authUser.value)) {
      emit('close');
      return;
    }
    try {
      const res = await api.get(`/api/procedures/${props.procedureId}`);
      if (!loadRequests.isCurrent(token)) return;
      loadFromResponse(res.data);
    } catch (err) {
      if (!loadRequests.isCurrent(token)) return;
      showActionError(err, 'Could not open the procedure, try again');
      emit('close');
      return;
    }
  } else {
    form.owner_id = authUser.value?.id || '';
    markSaved();
  }
  loaded.value = true;
}

// Watch show prop to load data when modal opens and reset when it closes
watch(
  () => props.show,
  async (newVal) => {
    if (newVal) {
      resetForm();
      await loadData();
    } else {
      loadRequests.invalidate();
    }
  },
);

onBeforeUnmount(() => {
  editor.value?.destroy();
});
</script>

<style>
.tiptap-editor .tiptap-editor-content { min-height: 300px; }
.tiptap-editor .ProseMirror {
  padding: 1rem 1.5rem;
  min-height: 300px;
  color: #cbd5e1;
  font-size: 0.875rem;
  line-height: 1.75;
  outline: none;
}
.tiptap-editor .ProseMirror p.is-editor-empty:first-child::before {
  content: attr(data-placeholder);
  float: left;
  color: #475569;
  pointer-events: none;
  height: 0;
}
.tiptap-editor .ProseMirror h1, .tiptap-editor .ProseMirror h2, .tiptap-editor .ProseMirror h3 { color: #f1f5f9; font-weight: 700; margin: 1em 0 0.5em; }
.tiptap-editor .ProseMirror h1 { font-size: 1.5rem; }
.tiptap-editor .ProseMirror h2 { font-size: 1.25rem; }
.tiptap-editor .ProseMirror h3 { font-size: 1.1rem; }
.tiptap-editor .ProseMirror ul, .tiptap-editor .ProseMirror ol { padding-left: 1.5em; margin: 0.5em 0; }
.tiptap-editor .ProseMirror code { background: rgba(255,255,255,0.08); padding: 0.15em 0.4em; border-radius: 0.25rem; }
.tiptap-editor .ProseMirror pre { background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); border-radius: 0.5rem; padding: 1em; overflow-x: auto; }
.tiptap-editor .ProseMirror pre code { background: none; padding: 0; }
.tiptap-editor .ProseMirror blockquote { border-left: 3px solid rgba(99,102,241,0.4); padding-left: 1em; color: #94a3b8; }
.tiptap-editor .ProseMirror img { max-width: 100%; border-radius: 0.5rem; margin: 1em 0; }
.tiptap-editor .ProseMirror strong { color: #f1f5f9; }
</style>
