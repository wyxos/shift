import axios from 'axios'

export const MAX_UPLOAD_BYTES = 40 * 1024 * 1024

type InitResponse = {
  upload_id: string
  chunk_size: number
  total_chunks: number
  max_bytes: number
}

type StatusResponse = {
  upload_id: string
  uploaded_chunks: number[]
  total_chunks: number
  chunk_size: number
}

export type ChunkedUploadResult = {
  original_filename: string
  path: string
  url?: string
}

type ChunkedUploadOptions = {
  file: File
  tempIdentifier: string
  onProgress?: (percent: number) => void
  axiosInstance?: typeof axios
  retryLimit?: number
}

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

export async function uploadChunkedFile(options: ChunkedUploadOptions): Promise<ChunkedUploadResult> {
  const client = options.axiosInstance ?? axios
  const { file, tempIdentifier } = options
  const retryLimit = options.retryLimit ?? 2

  if (file.size > MAX_UPLOAD_BYTES) {
    throw new Error('File exceeds 40MB limit')
  }

  const initRes = await client.post(route('attachments.upload-init') as string, {
    filename: file.name,
    size: file.size,
    temp_identifier: tempIdentifier,
    mime_type: file.type || null,
  })

  const initData = initRes.data as InitResponse
  const uploadId = initData.upload_id
  const chunkSize = initData.chunk_size || 5 * 1024 * 1024
  const totalChunks = initData.total_chunks || Math.ceil(file.size / chunkSize)

  let uploaded = new Set<number>()
  try {
    const statusRes = await client.get(route('attachments.upload-status') as string, {
      params: { upload_id: uploadId },
    })
    const statusData = statusRes.data as StatusResponse
    uploaded = new Set(statusData.uploaded_chunks || [])
  } catch {
    // If status fails, continue with empty set (non-resumable fallback)
  }

  let uploadedBytes = 0
  for (let i = 0; i < totalChunks; i++) {
    const start = i * chunkSize
    const end = Math.min(start + chunkSize, file.size)
    const size = Math.max(0, end - start)
    if (uploaded.has(i)) {
      uploadedBytes += size
    }
  }

  if (options.onProgress) {
    options.onProgress(Math.min(100, Math.round((uploadedBytes / file.size) * 100)))
  }

  const uploadChunk = async (index: number) => {
    const start = index * chunkSize
    const end = Math.min(start + chunkSize, file.size)
    const size = Math.max(0, end - start)
    const chunk = file.slice(start, end)

    const formData = new FormData()
    formData.append('upload_id', uploadId)
    formData.append('chunk_index', String(index))
    formData.append('chunk', chunk, file.name)

    await client.post(route('attachments.upload-chunk') as string, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (evt: ProgressEvent) => {
        if (!options.onProgress) return
        const loaded = Math.min(size, (evt as any).loaded ?? 0)
        const percent = Math.min(100, Math.round(((uploadedBytes + loaded) / file.size) * 100))
        options.onProgress(percent)
      },
    })

    uploadedBytes += size
    if (options.onProgress) {
      options.onProgress(Math.min(100, Math.round((uploadedBytes / file.size) * 100)))
    }
  }

  for (let i = 0; i < totalChunks; i++) {
    if (uploaded.has(i)) continue

    let attempt = 0
    while (true) {
      try {
        await uploadChunk(i)
        break
      } catch (err) {
        attempt++
        if (attempt > retryLimit) {
          throw err
        }
        await sleep(300 * attempt)
        try {
          const statusRes = await client.get(route('attachments.upload-status') as string, {
            params: { upload_id: uploadId },
          })
          const statusData = statusRes.data as StatusResponse
          uploaded = new Set(statusData.uploaded_chunks || [])
          if (uploaded.has(i)) {
            break
          }
        } catch {
          // keep retrying
        }
      }
    }
  }

  const completeRes = await client.post(route('attachments.upload-complete') as string, {
    upload_id: uploadId,
  })

  return completeRes.data as ChunkedUploadResult
}
