import { onBeforeUnmount, onMounted, type Ref } from 'vue'
import { useQuasar } from 'quasar'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'

/** Asks before leaving a page with unsaved work (in-app navigation, reload or closing the tab). */
export function useLeaveGuard(dirty: Readonly<Ref<boolean>>): void {
  const $q = useQuasar()

  const confirmLeave = (): true | Promise<boolean> => {
    if (!dirty.value) return true
    return new Promise<boolean>((resolve) => {
      $q.dialog({
        title: 'Leave without saving?',
        message: 'Your changes on this page have not been saved.',
        cancel: { flat: true, noCaps: true, label: 'Keep editing' },
        ok: { color: 'negative', unelevated: true, noCaps: true, label: 'Leave' },
        focus: 'cancel',
        persistent: true,
      }).onOk(() => { resolve(true) }).onCancel(() => { resolve(false) })
    })
  }
  onBeforeRouteLeave(confirmLeave)
  // Same page, different record (e.g. one document to another) reuses the component.
  onBeforeRouteUpdate(confirmLeave)

  function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value) event.preventDefault()
  }
  onMounted(() => { window.addEventListener('beforeunload', onBeforeUnload) })
  onBeforeUnmount(() => { window.removeEventListener('beforeunload', onBeforeUnload) })
}
