import { Link } from 'react-router-dom';

export default function MainLayout({ children }) {
    return (
        <div className="min-h-screen bg-gray-100">
            <header className="bg-white border-b">
                <div className="px-6 py-4">
                    <h1 className="text-xl font-semibold">
                        Laravel React Starter
                    </h1>
                </div>
            </header>

            <div className="flex">
                <aside className="w-64 min-h-[calc(100vh-65px)] bg-white border-r">
                    <nav className="p-4">
                        <Link
                            to="/dashboard"
                            className="block px-4 py-2 rounded hover:bg-gray-100"
                        >
                            Dashboard
                        </Link>

                        <Link
                            to="/users"
                            className="block px-4 py-2 mt-1 rounded hover:bg-gray-100"
                        >
                            Users
                        </Link>
                    </nav>
                </aside>

                <main className="flex-1 p-6">
                    {children}
                </main>
            </div>
        </div>
    );
}