import { BrowserRouter } from 'react-router-dom';
import { ConfigProvider } from 'antd';
import AppRoutes from './routes/AppRoutes';

// Brand-wide AntD theme: every "primary" element (buttons, hover/active
// states, etc.) uses the app's red instead of AntD's default blue — set
// once here so nothing needs a per-component CSS override.
const theme = {
  token: {
    colorPrimary: '#8c1d2d',
  },
};

function App() {
  return (
    <ConfigProvider theme={theme}>
      <BrowserRouter>
        <AppRoutes />
      </BrowserRouter>
    </ConfigProvider>
  );
}

export default App;