<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FIFO Tracking</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/all.min.css') }}">
</head>

<body class="bg-slate-900 h-screen w-full flex items-center justify-center relative overflow-hidden">

    <!-- Background Decoration -->
    <div class="absolute top-0 left-0 w-full h-full overflow-hidden z-0">
        <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-blue-600/20 rounded-full blur-[100px]"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-purple-600/20 rounded-full blur-[100px]">
        </div>
    </div>

    <!-- Login Card -->
    <div
        class="relative z-10 w-full max-w-md bg-white/10 backdrop-blur-lg border border-white/20 rounded-2xl shadow-2xl overflow-hidden p-8">

        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-white mb-2">FIFO Tracking</h1>
            <p class="text-blue-200">Production System</p>
        </div>

        <form action="{{ route('login.post') }}" method="POST" class="space-y-6">
            @csrf

            <!-- Email Input -->
            <div>
                <label for="email" class="block text-sm font-medium text-blue-100 mb-1">Email Address</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-envelope text-blue-300"></i>
                    </div>
                    <input type="email" name="email" id="email"
                        class="block w-full pl-10 pr-3 py-2.5 bg-slate-800/50 border border-slate-600 text-white rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 placeholder-slate-400 transition-all duration-300"
                        placeholder="your@email.com" required value="{{ old('email') }}">
                </div>
                @error('email')
                    <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <!-- Password Input -->
            <div>
                <label for="password" class="block text-sm font-medium text-blue-100 mb-1">Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-lock text-blue-300"></i>
                    </div>
                    <input type="password" name="password" id="password"
                        class="block w-full pl-10 pr-3 py-2.5 bg-slate-800/50 border border-slate-600 text-white rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 placeholder-slate-400 transition-all duration-300"
                        placeholder="••••••••" required>
                </div>
            </div>

            <!-- Remember Me & Forgot Password -->
            <div class="flex items-center justify-between text-sm">
                <div class="flex items-center">
                    <input id="remember" name="remember" type="checkbox"
                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-slate-600 rounded bg-slate-800/50">
                    <label for="remember" class="ml-2 block text-blue-200">
                        Ingat Saya
                    </label>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit"
                class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 focus:ring-offset-slate-900 transition-all duration-300 transform hover:scale-[1.02]">
                Sign In
            </button>
        </form>

        <div class="mt-6 text-center text-xs text-slate-400">
            &copy; {{ date('Y') }} Production System
        </div>
    </div>

</body>

</html>