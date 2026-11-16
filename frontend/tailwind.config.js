import tailwindcssAnimate from 'tailwindcss-animate'

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts,tsx}'],
  theme: {
    extend: {
      colors: {
        // 与 prototype/assets/prototype.css 的设计令牌保持一致
        primary: {
          DEFAULT: '#2B5CE6',
          light: '#4F7DF3',
          deep: '#1E3FA8',
        },
        ink: {
          DEFAULT: '#1F2430',
          sub: '#5A6478',
          mute: '#8A93A6',
        },
        page: '#F5F7FB',
        surface: '#FFFFFF',
        dark: '#1A1F2B',
        line: '#E8ECF5',
        success: '#16A34A',
        danger: '#E5484D',
        warn: '#F5A524',
      },
      fontFamily: {
        sans: ['"PingFang SC"', '"Microsoft YaHei"', 'system-ui', '-apple-system', 'Arial', 'sans-serif'],
      },
      borderRadius: {
        xl2: '18px',
        xl3: '14px',
      },
      boxShadow: {
        sh1: '0 1px 2px rgba(27,35,60,.05), 0 4px 12px rgba(27,35,60,.05)',
        sh2: '0 2px 6px rgba(27,35,60,.07), 0 12px 28px rgba(27,35,60,.09)',
        sh3: '0 8px 20px rgba(43,92,230,.16), 0 24px 48px rgba(27,35,60,.13)',
      },
      backgroundImage: {
        'grad-primary': 'linear-gradient(135deg, #4F7DF3 0%, #2B5CE6 55%, #1E3FA8 100%)',
        'grad-soft': 'linear-gradient(160deg, #F8FAFF 0%, #EDF2FE 100%)',
      },
    },
  },
  plugins: [tailwindcssAnimate],
}
