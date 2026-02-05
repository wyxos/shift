import axios, { type AxiosInstance, type AxiosProgressEvent } from 'axios'

declare const route: undefined | ((name: string, params?: Record<string, unknown>) => string)

export const MAX_UPLOAD_BYTES = 40 * 1024 * 1024

export type UploadEndpoints = {
  init: string
  status: string
  chunk: string
  complete: string
}

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
  axiosInstance?: AxiosInstance
  retryLimit?: number
  endpoints?: UploadEndpoints
}

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function resolveEndpoints(endpoints?: UploadEndpoints): UploadEndpoints {
  if (endpoints) return endpoints
  if (typeof route === 'function') {
    return {
      init: route('attachments.upload-init') as string,
      status: route('attachments.upload-status') as string,
      chunk: route('attachments.upload-chunk') as string,
      complete: route('attachments.upload-complete') as string,
    }
  }
  throw new Error('Chunked upload endpoints not configured')
}

export async function uploadChunkedFile(options: ChunkedUploadOptions): Promise<ChunkedUploadResult> {
  const client = options.axiosInstance ?? axios
  const { file, tempIdentifier } = options
  const retryLimit = options.retryLimit ?? 2
  const endpoints = resolveEndpoints(options.endpoints)

  if (file.size > MAX_UPLOAD_BYTES) {
    throw new Error('File exceeds 40MB limit')
  }

  const initRes = await client.post(endpoints.init, {
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
    const statusRes = await client.get(endpoints.status, {
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

    await client.post(endpoints.chunk, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (evt: AxiosProgressEvent) => {
        if (!options.onProgress) return
        const loaded = Math.min(size, evt.loaded ?? 0)
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
          const statusRes = await client.get(endpoints.status, {
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

  const completeRes = await client.post(endpoints.complete, {
    upload_id: uploadId,
  })

  return completeRes.data as ChunkedUploadResult
}
