export default function ApplicationLogo({ className = '', alt = 'Gestione Assenze', ...props }) {
    return (
        <img
            src="/logo.jpg"
            alt={alt}
            className={className}
            {...props}
        />
    );
}
