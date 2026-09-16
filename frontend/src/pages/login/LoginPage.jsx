import { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { Form, Input, Button, Typography, Alert, Checkbox } from 'antd';
import { BellOutlined, ExpandOutlined, LockOutlined, MailOutlined, MoonOutlined } from '@ant-design/icons';
import { loginUser } from '../../store/slices/authSlice';
import { dashboardPathForRoles } from '../../routes/roles';
import BrandHeader from '../../components/BrandHeader';

const { Title, Text } = Typography;

const loginHeaderActions = (
  <div className="header-actions">
    <button type="button" aria-label="Toggle dark mode"><MoonOutlined /></button>
    <button type="button" aria-label="Enter fullscreen"><ExpandOutlined /></button>
    <button type="button" aria-label="Notifications"><BellOutlined /></button>
  </div>
);
export default function LoginPage() {
  const dispatch = useDispatch();
  const navigate = useNavigate();
  const { status, error, user, token } = useSelector((state) => state.auth);

  useEffect(() => {
    if (token && user) {
      navigate(dashboardPathForRoles(user.roles, user.active_role), { replace: true });
    }
  }, [token, user, navigate]);

  const onFinish = ({ email, password }) => {
    dispatch(loginUser({ email, password }));
  };

  return (
    <div className="app-frame login-frame">
      <BrandHeader title={null} subtitle="" right={loginHeaderActions} />
      <main className="login-content"><section className="login-card">
        <Title level={2}>MNH: Sign In</Title>
        <Text className="login-subtitle">Access the hospital queue management system</Text>

        {error && <Alert type="error" message={error} className="login-alert" showIcon />}

        <Form layout="vertical" onFinish={onFinish} requiredMark={false}>
          <Form.Item
            label="Email Address"
            name="email"
            rules={[{ required: true, message: 'Email is required' }, { type: 'email', message: 'Enter a valid email' }]}
          >
            <Input prefix={<MailOutlined />} placeholder="Email Address" autoComplete="username" />
          </Form.Item>

          <Form.Item
            label="Password"
            name="password"
            rules={[{ required: true, message: 'Password is required' }]}
          >
            <Input.Password prefix={<LockOutlined />} placeholder="Password" autoComplete="current-password" />
          </Form.Item>

          <div className="login-options"><Checkbox>Remember me</Checkbox><a href="/login" onClick={(event) => event.preventDefault()}>Forgot Password? <strong>Reset</strong></a></div>
          <Button className="login-submit" type="primary" htmlType="submit" block loading={status === 'loading'}>Sign In</Button>
        </Form>
        <p className="signup-prompt">Don&apos;t have an account? <a href="/login" onClick={(event) => event.preventDefault()}>Sign Up</a></p>
      </section></main>
    </div>
  );
}