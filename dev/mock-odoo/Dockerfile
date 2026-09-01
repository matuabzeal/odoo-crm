FROM python:3.13-slim
WORKDIR /app
COPY app.py /app/app.py
RUN mkdir -p /data
EXPOSE 8069
CMD ["python", "/app/app.py"]
