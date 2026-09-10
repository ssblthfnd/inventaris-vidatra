/** Full-page spinner shown while auth state is being resolved. */
export default function LoadingScreen({ message = 'Memuat…' }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50">
      <div className="flex flex-col items-center gap-3 text-gray-500">
        <span
          className="h-8 w-8 animate-spin rounded-full border-2 border-gray-300 border-t-gray-700"
          aria-hidden="true"
        />
        <p className="text-sm">{message}</p>
      </div>
    </div>
  );
}
